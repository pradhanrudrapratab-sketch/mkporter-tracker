<?php

declare(strict_types=1);

namespace Porter\Ride;

use Porter\Config\Logger;

class PorterParser
{
    private const ALLOWED_HOST  = 'porter.in';
    private const ALLOWED_PATH  = '/track_live_order_v2';
    private const CURL_TIMEOUT  = 20;
    private const CURL_CONNECT  = 10;

    /**
     * Validate that the URL is a legitimate Porter tracking URL (SSRF protection).
     */
    public static function validateUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed) return false;
        if (($parsed['scheme'] ?? '') !== 'https') return false;
        if (($parsed['host'] ?? '') !== self::ALLOWED_HOST) return false;
        if (!str_starts_with($parsed['path'] ?? '', self::ALLOWED_PATH)) return false;
        return true;
    }

    /**
     * Fetch Porter page and parse ride data.
     * Returns ['success' => bool, 'data' => RideData|null, 'error' => string|null, 'http_status' => int]
     */
    public static function fetch(string $url): array
    {
        if (!self::validateUrl($url)) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_INVALID_URL', 'http_status' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PorterTracker/1.0)',
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $html       = curl_exec($ch);
        $curlError  = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || !empty($curlError)) {
            Logger::warn('PorterParser', 'curl_error', ['error' => $curlError]);
            return ['success' => false, 'data' => null, 'error' => 'PORTER_CURL', 'http_status' => 0];
        }

        if ($httpStatus === 429) {
            return ['success' => false, 'data' => null, 'error' => 'RATE_LIMIT', 'http_status' => $httpStatus];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_HTTP', 'http_status' => $httpStatus];
        }

        return self::parseHtml((string)$html, $httpStatus);
    }

    private static function parseHtml(string $html, int $httpStatus): array
    {
        // Extract Next.js payload from self.__next_f.push([1,"..."])
        $payloads = self::extractNextJsPayloads($html);
        if (empty($payloads)) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_PARSE', 'http_status' => $httpStatus];
        }

        $combined = implode('', $payloads);

        // Find order_details JSON block
        $pos = strpos($combined, '"order_details":');
        if ($pos === false) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_MISSING_ORDER', 'http_status' => $httpStatus];
        }

        // Extract the JSON object after "order_details":
        $jsonStart = strpos($combined, '{', $pos);
        if ($jsonStart === false) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_JSON', 'http_status' => $httpStatus];
        }

        $jsonStr = self::extractBalancedObject($combined, $jsonStart);
        if ($jsonStr === null) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_JSON', 'http_status' => $httpStatus];
        }

        $orderDetails = json_decode($jsonStr, true);
        if (!is_array($orderDetails)) {
            return ['success' => false, 'data' => null, 'error' => 'PORTER_JSON', 'http_status' => $httpStatus];
        }

        $data = self::normalize($orderDetails);
        return ['success' => true, 'data' => $data, 'error' => null, 'http_status' => $httpStatus];
    }

    private static function extractNextJsPayloads(string $html): array
    {
        $results = [];
        // Match self.__next_f.push([1,"..."]) or self.__next_f.push([1,'...'])
        preg_match_all('/self\.__next_f\.push\(\[1,\s*"((?:[^"\\\\]|\\\\.)*)"\]\)/s', $html, $matches);

        foreach ($matches[1] as $raw) {
            // Unescape the JSON string
            $decoded = json_decode('"' . $raw . '"');
            if (is_string($decoded)) {
                $results[] = $decoded;
            }
        }
        return $results;
    }

    /**
     * Balanced brace extractor - handles nested objects, strings, escaped quotes.
     */
    private static function extractBalancedObject(string $str, int $start): ?string
    {
        $len    = strlen($str);
        $depth  = 0;
        $inStr  = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $c = $str[$i];

            if ($escape) {
                $escape = false;
                continue;
            }
            if ($c === '\\' && $inStr) {
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $inStr = !$inStr;
                continue;
            }
            if (!$inStr) {
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($str, $start, $i - $start + 1);
                    }
                }
            }
        }
        return null;
    }

    private static function normalize(array $d): array
    {
        $partner  = $d['partner'] ?? [];
        $pickup   = $d['pickup'] ?? [];
        $drop     = $d['drop'] ?? [];
        $partLoc  = $d['partnerLocation'] ?? null;
        $waypts   = $d['waypointLocations'] ?? [];

        $lat = $lng = null;
        if (is_array($partLoc) && isset($partLoc['lat'], $partLoc['lng'])) {
            $lat = self::validCoord($partLoc['lat'], -90, 90);
            $lng = self::validCoord($partLoc['lng'], -180, 180);
        }

        return [
            'booking_id'        => (string)($d['booking_id'] ?? ''),
            'status'            => strtolower((string)($d['status'] ?? 'unknown')),
            'crn'               => (string)($d['crn'] ?? ''),
            'geo_region_id'     => $d['geo_region_id'] ?? null,

            'partner_name'      => (string)($partner['name'] ?? ''),
            'partner_mobile'    => (string)($partner['mobile'] ?? ''),
            'vehicle_type'      => (string)($partner['vehicleType'] ?? $d['vehicle_type'] ?? ''),
            'vehicle_number'    => (string)($partner['vehicleNumber'] ?? ''),

            'pickup_landmark'   => (string)($pickup['landmark'] ?? ''),
            'pickup_lat'        => self::validCoord($pickup['lat'] ?? null, -90, 90),
            'pickup_lng'        => self::validCoord($pickup['lng'] ?? null, -180, 180),

            'drop_landmark'     => (string)($drop['landmark'] ?? ''),
            'drop_lat'          => self::validCoord($drop['lat'] ?? null, -90, 90),
            'drop_lng'          => self::validCoord($drop['lng'] ?? null, -180, 180),

            'waypoints'         => self::normalizeWaypoints($waypts),
            'has_waypoints'     => !empty($waypts),
            'is_rental'         => (bool)($d['isRental'] ?? false),
            'is_helper'         => (bool)($d['isHelper'] ?? false),
            'is_outstation'     => (bool)($d['isOutstation'] ?? false),

            'partner_lat'       => $lat,
            'partner_lng'       => $lng,

            'trip_accepted_time' => isset($d['trip_accepted_time']) ? (int)$d['trip_accepted_time'] : null,
            'trip_ended_time'    => isset($d['trip_ended_time']) ? (int)$d['trip_ended_time'] : null,

            'customer_name'     => (string)($d['customerInfo']['name'] ?? ''),
            'customer_number'   => (string)($d['customerInfo']['number'] ?? ''),
        ];
    }

    private static function normalizeWaypoints(array $waypts): array
    {
        $result = [];
        foreach ($waypts as $i => $wp) {
            $lat = self::validCoord($wp['lat'] ?? null, -90, 90);
            $lng = self::validCoord($wp['lng'] ?? null, -180, 180);
            $result[] = [
                'sequence' => $i + 1,
                'landmark' => (string)($wp['landmark'] ?? ''),
                'lat'      => $lat,
                'lng'      => $lng,
            ];
        }
        return $result;
    }

    private static function validCoord(mixed $val, float $min, float $max): ?float
    {
        if ($val === null || $val === '' || !is_numeric($val)) return null;
        $f = (float)$val;
        if (!is_finite($f) || $f < $min || $f > $max) return null;
        return $f;
    }
}
