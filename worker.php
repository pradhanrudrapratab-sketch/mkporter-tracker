<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Porter\Config\Config;
use Porter\Config\Database;
use Porter\Config\Logger;
use Porter\Ride\PorterParser;
use Porter\Telegram\ReportGenerator;

Config::load();
date_default_timezone_set('UTC');

Logger::info('Worker', 'started', ['pid' => getmypid()]);

$lastCleanup  = 0;
$cleanupEvery = 3600; // Check once per hour

while (true) {
    try {
        $pdo = Database::getInstance();

        // ── INACTIVE USER CLEANUP ────────────────────────────────────────────
        if ((time() - $lastCleanup) >= $cleanupEvery) {
            $inactiveDays = Config::get('inactive_days', 30);
            $deleted = $pdo->prepare("
                DELETE FROM users
                WHERE last_active_at < NOW() - INTERVAL '{$inactiveDays} days'
            ");
            $deleted->execute();
            $count = $deleted->rowCount();
            if ($count > 0) {
                Logger::info('Worker', 'cleanup_inactive_users', ['deleted' => $count]);
            }
            $lastCleanup = time();
        }

        // ── PROCESS DUE LIVE RIDES ────────────────────────────────────────────
        // Claim rides with SKIP LOCKED to prevent duplicate processing
        $stmt = $pdo->prepare("
            UPDATE rides
            SET processing_until = NOW() + INTERVAL '2 minutes'
            WHERE id IN (
                SELECT id FROM rides
                WHERE status = 'live'
                  AND next_poll_at <= NOW()
                  AND (processing_until IS NULL OR processing_until < NOW())
                ORDER BY next_poll_at ASC
                LIMIT 10
                FOR UPDATE SKIP LOCKED
            )
            RETURNING *
        ");
        $stmt->execute();
        $dueRides = $stmt->fetchAll();

        foreach ($dueRides as $ride) {
            processRide($pdo, $ride);
        }

        // ── PROCESS DUE TELEGRAM REPORTS ────────────────────────────────────
        $rStmt = $pdo->prepare("
            UPDATE rides
            SET processing_until = NOW() + INTERVAL '3 minutes'
            WHERE id IN (
                SELECT id FROM rides
                WHERE status = 'completed'
                  AND report_status IN ('pending', NULL)
                  AND report_due_at <= NOW()
                  AND (processing_until IS NULL OR processing_until < NOW())
                  AND COALESCE(report_attempts, 0) < 5
                ORDER BY report_due_at ASC
                LIMIT 5
                FOR UPDATE SKIP LOCKED
            )
            RETURNING *
        ");
        $rStmt->execute();
        $dueReports = $rStmt->fetchAll();

        foreach ($dueReports as $ride) {
            processReport($pdo, $ride);
        }

    } catch (\Exception $e) {
        Logger::error('Worker', 'loop_error', ['error' => $e->getMessage()]);
        Database::reset(); // Force reconnect next iteration
    }

    sleep(5); // Check for new work every 5 seconds
}

// ──────────────────────────────────────────────────────────────────────────────

function processRide(\PDO $pdo, array $ride): void
{
    Logger::debug('Worker', 'polling_ride', ['ride_id' => $ride['id'], 'booking_id' => $ride['booking_id']]);

    $result  = PorterParser::fetch($ride['tracking_url']);
    $interval = (int)Config::get('track_interval_seconds', 30);

    if (!$result['success']) {
        $errorCode = $result['error'] ?? 'PORTER_UNKNOWN';
        $backoff   = $errorCode === 'RATE_LIMIT' ? 120 : $interval;

        $pdo->prepare("
            UPDATE rides SET
                status = CASE WHEN status = 'unknown' THEN 'unknown' ELSE status END,
                error_count = COALESCE(error_count, 0) + 1,
                last_error = ?,
                last_error_at = NOW(),
                last_polled_at = NOW(),
                next_poll_at = NOW() + INTERVAL '{$backoff} seconds',
                processing_until = NULL,
                poll_count = COALESCE(poll_count, 0) + 1,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$errorCode, $ride['id']]);

        Logger::warn('Worker', 'poll_failed', ['ride_id' => $ride['id'], 'error' => $errorCode]);
        return;
    }

    $data      = $result['data'];
    $status    = $data['status'];
    $isCompleted = in_array($status, ['completed', 'delivered', 'done']);

    $pdo->beginTransaction();
    try {
        // Save GPS location if valid
        $seqRow = $pdo->prepare('SELECT COALESCE(MAX(sequence),0)+1 AS next_seq FROM location_history WHERE ride_id=?');
        $seqRow->execute([$ride['id']]);
        $nextSeq = (int)$seqRow->fetchColumn();

        $gpsSaved = false;
        if ($data['partner_lat'] !== null && $data['partner_lng'] !== null) {
            $pdo->prepare('INSERT INTO location_history (ride_id, sequence, latitude, longitude, recorded_at) VALUES (?,?,?,?,NOW())')
                ->execute([$ride['id'], $nextSeq, $data['partner_lat'], $data['partner_lng']]);
            $gpsSaved = true;
        }

        // Save/update waypoints (first time only)
        if ($data['has_waypoints'] && !empty($data['waypoints'])) {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM ride_waypoints WHERE ride_id=?');
            $existsStmt->execute([$ride['id']]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                foreach ($data['waypoints'] as $wp) {
                    $pdo->prepare('INSERT INTO ride_waypoints (ride_id,sequence,landmark,latitude,longitude) VALUES (?,?,?,?,?)')
                        ->execute([$ride['id'], $wp['sequence'], $wp['landmark'], $wp['lat'], $wp['lng']]);
                }
            }
        }

        // Build update
        if ($isCompleted) {
            $completedAt = !empty($data['trip_ended_time'])
                ? date('Y-m-d H:i:s', $data['trip_ended_time'])
                : null;
            $reportDelay  = (int)Config::get('report_delay_seconds', 600);

            $pdo->prepare("
                UPDATE rides SET
                    status = 'completed',
                    crn = COALESCE(NULLIF(?, ''), crn),
                    partner_name = NULLIF(?, ''),
                    vehicle_type = NULLIF(?, ''),
                    vehicle_number = NULLIF(?, ''),
                    pickup_landmark = NULLIF(?, ''),
                    pickup_lat = COALESCE(CAST(? AS DOUBLE PRECISION), pickup_lat),
                    pickup_lng = COALESCE(CAST(? AS DOUBLE PRECISION), pickup_lng),
                    drop_landmark = NULLIF(?, ''),
                    drop_lat = COALESCE(CAST(? AS DOUBLE PRECISION), drop_lat),
                    drop_lng = COALESCE(CAST(? AS DOUBLE PRECISION), drop_lng),
                    last_partner_lat = COALESCE(CAST(? AS DOUBLE PRECISION), last_partner_lat),
                    last_partner_lng = COALESCE(CAST(? AS DOUBLE PRECISION), last_partner_lng),
                    accepted_at = COALESCE(to_timestamp(?), accepted_at),
                    completed_at = COALESCE(?, to_timestamp(?), NOW()),
                    report_due_at = COALESCE(?, to_timestamp(?), NOW()) + INTERVAL '{$reportDelay} seconds',
                    report_status = 'pending',
                    last_polled_at = NOW(),
                    next_poll_at = NULL,
                    processing_until = NULL,
                    poll_count = COALESCE(poll_count, 0) + 1,
                    successful_poll_count = COALESCE(successful_poll_count, 0) + 1,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $data['crn'], $data['partner_name'], $data['vehicle_type'], $data['vehicle_number'],
                $data['pickup_landmark'], $data['pickup_lat'], $data['pickup_lng'],
                $data['drop_landmark'], $data['drop_lat'], $data['drop_lng'],
                $data['partner_lat'], $data['partner_lng'],
                $data['trip_accepted_time'],
                $completedAt, $data['trip_ended_time'],
                $completedAt, $data['trip_ended_time'],
                $ride['id'],
            ]);
        } else {
            $pdo->prepare("
                UPDATE rides SET
                    status = CASE WHEN ? IN ('live','active','on_the_way') THEN 'live' ELSE COALESCE(status, 'live') END,
                    crn = COALESCE(NULLIF(?, ''), crn),
                    partner_name = COALESCE(NULLIF(?, ''), partner_name),
                    vehicle_type = COALESCE(NULLIF(?, ''), vehicle_type),
                    vehicle_number = COALESCE(NULLIF(?, ''), vehicle_number),
                    pickup_landmark = COALESCE(NULLIF(?, ''), pickup_landmark),
                    pickup_lat = COALESCE(CAST(? AS DOUBLE PRECISION), pickup_lat),
                    pickup_lng = COALESCE(CAST(? AS DOUBLE PRECISION), pickup_lng),
                    drop_landmark = COALESCE(NULLIF(?, ''), drop_landmark),
                    drop_lat = COALESCE(CAST(? AS DOUBLE PRECISION), drop_lat),
                    drop_lng = COALESCE(CAST(? AS DOUBLE PRECISION), drop_lng),
                    last_partner_lat = COALESCE(CAST(? AS DOUBLE PRECISION), last_partner_lat),
                    last_partner_lng = COALESCE(CAST(? AS DOUBLE PRECISION), last_partner_lng),
                    accepted_at = COALESCE(to_timestamp(?), accepted_at),
                    last_polled_at = NOW(),
                    next_poll_at = NOW() + INTERVAL '{$interval} seconds',
                    processing_until = NULL,
                    poll_count = COALESCE(poll_count, 0) + 1,
                    successful_poll_count = COALESCE(successful_poll_count, 0) + 1,
                    error_count = 0,
                    last_error = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $status,
                $data['crn'], $data['partner_name'], $data['vehicle_type'], $data['vehicle_number'],
                $data['pickup_landmark'], $data['pickup_lat'], $data['pickup_lng'],
                $data['drop_landmark'], $data['drop_lat'], $data['drop_lng'],
                $data['partner_lat'], $data['partner_lng'],
                $data['trip_accepted_time'],
                $ride['id'],
            ]);
        }

        $pdo->commit();

        Logger::info('Worker', 'poll_success', [
            'ride_id'    => $ride['id'],
            'booking_id' => $ride['booking_id'],
            'status'     => $isCompleted ? 'completed' : 'live',
            'gps_saved'  => $gpsSaved,
        ]);

    } catch (\Exception $e) {
        $pdo->rollBack();
        Logger::error('Worker', 'db_update_failed', ['ride_id' => $ride['id'], 'error' => $e->getMessage()]);
        // Release processing lock
        $pdo->prepare('UPDATE rides SET processing_until=NULL WHERE id=?')->execute([$ride['id']]);
    }
}

function processReport(\PDO $pdo, array $ride): void
{
    Logger::info('Worker', 'sending_report', ['ride_id' => $ride['id']]);

    $attempts = (int)($ride['report_attempts'] ?? 0) + 1;

    $success = ReportGenerator::sendFinalReport($ride);

    if ($success) {
        $pdo->prepare("UPDATE rides SET report_status='sent', report_sent_at=NOW(), processing_until=NULL WHERE id=?")
            ->execute([$ride['id']]);
        Logger::info('Worker', 'report_sent', ['ride_id' => $ride['id']]);
    } else {
        // Exponential backoff
        $delays = [60, 300, 900, 1800, 0]; // 0 = stop retrying at attempt 5
        $delay  = $delays[min($attempts - 1, count($delays) - 1)];

        if ($attempts >= 5 || $delay === 0) {
            $pdo->prepare("UPDATE rides SET report_status='failed', report_attempts=?, processing_until=NULL, report_last_error='Max retry attempts reached' WHERE id=?")
                ->execute([$attempts, $ride['id']]);
            Logger::warn('Worker', 'report_failed_final', ['ride_id' => $ride['id']]);
        } else {
            $pdo->prepare("UPDATE rides SET report_status='pending', report_attempts=?, report_due_at=NOW()+INTERVAL '{$delay} seconds', processing_until=NULL WHERE id=?")
                ->execute([$attempts, $ride['id']]);
            Logger::warn('Worker', 'report_retry_scheduled', ['ride_id' => $ride['id'], 'attempt' => $attempts, 'delay' => $delay]);
        }
    }
}
