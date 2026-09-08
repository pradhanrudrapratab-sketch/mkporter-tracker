<?php

declare(strict_types=1);

namespace Porter\Ride;

use Porter\Config\Database;
use Porter\Config\Logger;
use Porter\Middleware\Auth;
use Porter\Middleware\CSRF;

class RideController
{
    public static function list(): void
    {
        Auth::requireAuth();
        $pdo    = Database::getInstance();
        $userId = Auth::userId();

        $stmt = $pdo->prepare('
            SELECT r.*, 
                   (SELECT COUNT(*) FROM location_history lh WHERE lh.ride_id = r.id) AS gps_count
            FROM rides r
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC
        ');
        $stmt->execute([$userId]);
        $rides = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => ['rides' => $rides]]);
    }

    public static function add(): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();

        $url    = trim($_POST['tracking_url'] ?? '');
        $userId = Auth::userId();

        if (!PorterParser::validateUrl($url)) {
            self::jsonError('VALIDATION_ERROR', 'Please enter a valid Porter tracking URL (https://porter.in/track_live_order_v2?...).');
        }

        // Extract booking_id from URL
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $bookingId = $params['booking_id'] ?? '';

        if (empty($bookingId)) {
            self::jsonError('VALIDATION_ERROR', 'Could not extract booking ID from URL.');
        }

        $pdo = Database::getInstance();

        // Check for duplicate active ride for this user
        $stmt = $pdo->prepare("SELECT id FROM rides WHERE user_id = ? AND booking_id = ? AND status NOT IN ('completed','error')");
        $stmt->execute([$userId, $bookingId]);
        if ($stmt->fetch()) {
            self::jsonError('VALIDATION_ERROR', 'This ride is already being tracked.');
        }

        $stmt = $pdo->prepare('
            INSERT INTO rides (user_id, booking_id, tracking_url, status, next_poll_at, created_at, updated_at)
            VALUES (?, ?, ?, \'unknown\', NOW(), NOW(), NOW())
            RETURNING id
        ');
        $stmt->execute([$userId, $bookingId, $url]);
        $row = $stmt->fetch();

        Logger::info('Ride', 'ride_added', ['user_id' => $userId, 'ride_id' => $row['id'], 'booking_id' => $bookingId]);
        echo json_encode(['success' => true, 'data' => ['ride_id' => $row['id'], 'message' => 'Ride added. First tracking cycle will begin shortly.']]);
    }

    public static function get(int $rideId): void
    {
        Auth::requireAuth();
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT r.* FROM rides r WHERE r.id = ? AND r.user_id = ?');
        $stmt->execute([$rideId, Auth::userId()]);
        $ride = $stmt->fetch();

        if (!$ride) {
            self::jsonError('NOT_FOUND', 'Ride not found.', 404);
        }

        // Get waypoints
        $wStmt = $pdo->prepare('SELECT * FROM ride_waypoints WHERE ride_id = ? ORDER BY sequence');
        $wStmt->execute([$rideId]);
        $ride['waypoints'] = $wStmt->fetchAll();

        // Get location history (limited for dashboard)
        $lStmt = $pdo->prepare('SELECT * FROM location_history WHERE ride_id = ? ORDER BY sequence ASC');
        $lStmt->execute([$rideId]);
        $ride['locations'] = $lStmt->fetchAll();

        $ride['gps_count'] = count($ride['locations']);

        echo json_encode(['success' => true, 'data' => $ride]);
    }

    public static function stop(int $rideId): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();
        self::setStatus($rideId, 'stopped');
        Logger::info('Ride', 'ride_stopped', ['user_id' => Auth::userId(), 'ride_id' => $rideId]);
        echo json_encode(['success' => true, 'message' => 'Ride tracking stopped.']);
    }

    public static function resume(int $rideId): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE rides SET status='live', next_poll_at=NOW(), updated_at=NOW() WHERE id=? AND user_id=? AND status='stopped'");
        $stmt->execute([$rideId, Auth::userId()]);
        if ($stmt->rowCount() === 0) {
            self::jsonError('NOT_FOUND', 'Ride not found or not stopped.');
        }
        Logger::info('Ride', 'ride_resumed', ['user_id' => Auth::userId(), 'ride_id' => $rideId]);
        echo json_encode(['success' => true, 'message' => 'Ride tracking resumed.']);
    }

    public static function delete(int $rideId): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();
        $pdo = Database::getInstance();
        // ON DELETE CASCADE handles locations and waypoints
        $stmt = $pdo->prepare('DELETE FROM rides WHERE id = ? AND user_id = ?');
        $stmt->execute([$rideId, Auth::userId()]);
        if ($stmt->rowCount() === 0) {
            self::jsonError('NOT_FOUND', 'Ride not found.');
        }
        Logger::info('Ride', 'ride_deleted', ['user_id' => Auth::userId(), 'ride_id' => $rideId]);
        echo json_encode(['success' => true, 'message' => 'Ride deleted.']);
    }

    public static function retryReport(int $rideId): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE rides SET report_status='pending', report_due_at=NOW(), report_attempts=0, report_last_error=NULL WHERE id=? AND user_id=? AND status='completed' AND report_status='failed'");
        $stmt->execute([$rideId, Auth::userId()]);
        if ($stmt->rowCount() === 0) {
            self::jsonError('NOT_FOUND', 'Ride not eligible for retry.');
        }
        echo json_encode(['success' => true, 'message' => 'Report queued for retry.']);
    }

    public static function locations(int $rideId): void
    {
        Auth::requireAuth();
        $pdo  = Database::getInstance();
        // Verify ownership
        $stmt = $pdo->prepare('SELECT id FROM rides WHERE id=? AND user_id=?');
        $stmt->execute([$rideId, Auth::userId()]);
        if (!$stmt->fetch()) {
            self::jsonError('NOT_FOUND', 'Ride not found.', 404);
        }
        $lStmt = $pdo->prepare('SELECT sequence, recorded_at, latitude, longitude FROM location_history WHERE ride_id=? ORDER BY sequence ASC');
        $lStmt->execute([$rideId]);
        echo json_encode(['success' => true, 'data' => $lStmt->fetchAll()]);
    }

    private static function setStatus(int $rideId, string $status): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE rides SET status=?, updated_at=NOW() WHERE id=? AND user_id=?");
        $stmt->execute([$status, $rideId, Auth::userId()]);
        if ($stmt->rowCount() === 0) {
            self::jsonError('NOT_FOUND', 'Ride not found.', 404);
        }
    }

    private static function jsonError(string $code, string $message, int $status = 400): never
    {
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
        exit;
    }
}
