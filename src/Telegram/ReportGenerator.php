<?php

declare(strict_types=1);

namespace Porter\Telegram;

use Porter\Config\Config;
use Porter\Config\Database;
use Porter\Config\Encryption;
use Porter\Config\Logger;

class ReportGenerator
{
    public static function sendFinalReport(array $ride): bool
    {
        $pdo    = Database::getInstance();
        $userId = $ride['user_id'];

        $stmt = $pdo->prepare('SELECT bot_token_encrypted, chat_id FROM telegram_settings WHERE user_id = ? AND enabled = true');
        $stmt->execute([$userId]);
        $tg = $stmt->fetch();

        if (!$tg) {
            Logger::info('Report', 'no_telegram_config', ['ride_id' => $ride['id']]);
            // Mark as skipped so we don't keep retrying
            $pdo->prepare("UPDATE rides SET report_status='skipped', report_sent_at=NOW() WHERE id=?")
                ->execute([$ride['id']]);
            return true;
        }

        try {
            $token  = Encryption::decrypt($tg['bot_token_encrypted']);
            $chatId = $tg['chat_id'];
        } catch (\Exception $e) {
            Logger::error('Report', 'decrypt_failed', ['ride_id' => $ride['id']]);
            return false;
        }

        // Get locations
        $lStmt = $pdo->prepare('SELECT sequence, recorded_at, latitude, longitude FROM location_history WHERE ride_id=? ORDER BY sequence ASC');
        $lStmt->execute([$ride['id']]);
        $locations = $lStmt->fetchAll();

        // Get waypoints
        $wStmt = $pdo->prepare('SELECT sequence, landmark, latitude, longitude FROM ride_waypoints WHERE ride_id=? ORDER BY sequence');
        $wStmt->execute([$ride['id']]);
        $waypoints = $wStmt->fetchAll();

        $tz = new \DateTimeZone(Config::get('timezone', 'Asia/Kolkata'));

        $message = self::buildMessage($ride, $waypoints, $locations, $tz);
        $csv     = self::buildCsv($ride, $locations);

        // Send message
        $msgResult = TelegramService::sendMessage($token, $chatId, $message);
        if (!$msgResult['success']) {
            Logger::warn('Report', 'message_send_failed', ['ride_id' => $ride['id'], 'error' => $msgResult['error']]);
            return false;
        }

        // Send CSV if locations exist
        if (!empty($locations)) {
            $filename  = 'porter_gps_' . ($ride['crn'] ?: $ride['booking_id']) . '.csv';
            $csvResult = TelegramService::sendDocument($token, $chatId, $filename, $csv, '📍 GPS Track Data');
            if (!$csvResult['success']) {
                Logger::warn('Report', 'csv_send_failed', ['ride_id' => $ride['id']]);
                // Message was sent, continue — CSV failure is non-critical
            }
        }

        return true;
    }

    private static function buildMessage(array $ride, array $waypoints, array $locations, \DateTimeZone $tz): string
    {
        $crn = htmlspecialchars($ride['crn'] ?: $ride['booking_id']);
        $msg = "🚚 <b>PORTER RIDE COMPLETED</b>\n\n";
        $msg .= "CRN: <code>{$crn}</code>\n";
        $msg .= "Status: Completed\n\n";

        if (!empty($ride['vehicle_type'])) {
            $msg .= "Vehicle: " . htmlspecialchars($ride['vehicle_type']) . "\n";
        }
        if (!empty($ride['vehicle_number'])) {
            $msg .= "Vehicle No: " . htmlspecialchars($ride['vehicle_number']) . "\n";
        }
        if (!empty($ride['partner_name'])) {
            $msg .= "Driver: " . htmlspecialchars($ride['partner_name']) . "\n";
        }
        $msg .= "\n";

        if (!empty($ride['pickup_landmark'])) {
            $msg .= "📦 <b>Pickup:</b>\n" . htmlspecialchars($ride['pickup_landmark']) . "\n\n";
        }

        foreach ($waypoints as $wp) {
            if (!empty($wp['landmark'])) {
                $msg .= "📍 <b>Waypoint {$wp['sequence']}:</b>\n" . htmlspecialchars($wp['landmark']) . "\n\n";
            }
        }

        if (!empty($ride['drop_landmark'])) {
            $msg .= "🏁 <b>Drop:</b>\n" . htmlspecialchars($ride['drop_landmark']) . "\n\n";
        }

        // Times
        if (!empty($ride['accepted_at'])) {
            $dt = (new \DateTime($ride['accepted_at']))->setTimezone($tz);
            $msg .= "⏱ Accepted: " . $dt->format('d M Y, h:i:s A') . "\n";
        }
        if (!empty($ride['completed_at'])) {
            $dt = (new \DateTime($ride['completed_at']))->setTimezone($tz);
            $msg .= "✅ Completed: " . $dt->format('d M Y, h:i:s A') . "\n";
        }

        // Duration
        if (!empty($ride['accepted_at']) && !empty($ride['completed_at'])) {
            $start    = new \DateTime($ride['accepted_at']);
            $end      = new \DateTime($ride['completed_at']);
            $diff     = $start->diff($end);
            $duration = '';
            if ($diff->h > 0) $duration .= $diff->h . ' hr ';
            $duration .= $diff->i . ' min ' . $diff->s . ' sec';
            $msg .= "⏳ Duration: {$duration}\n";
        }

        $msg .= "\n📊 GPS Samples: " . count($locations) . "\n";

        // Approximate tracked distance
        if (count($locations) >= 2) {
            $dist = self::totalDistance($locations);
            $msg .= "📏 Tracked GPS distance: " . round($dist / 1000, 2) . " km\n";
        }

        $now = (new \DateTime())->setTimezone($tz);
        $msg .= "\n🕐 Report generated: " . $now->format('d M Y, h:i:s A');

        return $msg;
    }

    private static function buildCsv(array $ride, array $locations): string
    {
        $rows   = ["sequence,recorded_at,latitude,longitude,distance_from_previous_m"];
        $prevLat = $prevLng = null;

        foreach ($locations as $loc) {
            $dist = '';
            if ($prevLat !== null) {
                $dist = round(self::haversine($prevLat, $prevLng, (float)$loc['latitude'], (float)$loc['longitude']));
            }
            $rows[]  = implode(',', [
                $loc['sequence'],
                $loc['recorded_at'],
                $loc['latitude'],
                $loc['longitude'],
                $dist,
            ]);
            $prevLat = (float)$loc['latitude'];
            $prevLng = (float)$loc['longitude'];
        }

        return implode("\n", $rows);
    }

    private static function totalDistance(array $locations): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($locations); $i++) {
            $total += self::haversine(
                (float)$locations[$i - 1]['latitude'],
                (float)$locations[$i - 1]['longitude'],
                (float)$locations[$i]['latitude'],
                (float)$locations[$i]['longitude']
            );
        }
        return $total;
    }

    // Haversine formula — returns meters
    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $R * asin(sqrt($a));
    }
}
