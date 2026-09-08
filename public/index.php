<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Porter\Config\Config;
use Porter\Config\Database;
use Porter\Middleware\Auth;
use Porter\Middleware\CSRF;
use Porter\Auth\AuthController;
use Porter\Ride\RideController;
use Porter\Telegram\TelegramController;

Config::load();
date_default_timezone_set('UTC');

Auth::initSession();

$method = $_SERVER['REQUEST_METHOD'];
$uri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

// ── JSON API ROUTES ────────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Health check
if ($uri === '/api/health' && $method === 'GET') {
    header('Content-Type: application/json');
    try {
        Database::getInstance()->query('SELECT 1');
        $db = 'ok';
    } catch (\Exception) {
        $db = 'error';
    }
    echo json_encode(['success' => true, 'status' => 'ok', 'database' => $db, 'timestamp' => date('c')]);
    exit;
}

// Auth API
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json');

    match (true) {
        $uri === '/api/auth/register'     && $method === 'POST' => AuthController::register(),
        $uri === '/api/auth/login'        && $method === 'POST' => AuthController::login(),
        $uri === '/api/auth/logout'       && $method === 'POST' => AuthController::logout(),
        $uri === '/api/password/change'   && $method === 'POST' => AuthController::changePassword(),

        $uri === '/api/rides'             && $method === 'GET'  => RideController::list(),
        $uri === '/api/rides'             && $method === 'POST' => RideController::add(),

        // /api/rides/{id}
        preg_match('#^/api/rides/(\d+)$#', $uri, $m) && $method === 'GET'    => RideController::get((int)$m[1]),
        preg_match('#^/api/rides/(\d+)$#', $uri, $m) && $method === 'DELETE' => RideController::delete((int)$m[1]),
        preg_match('#^/api/rides/(\d+)/stop$#', $uri, $m)   && $method === 'POST' => RideController::stop((int)$m[1]),
        preg_match('#^/api/rides/(\d+)/resume$#', $uri, $m) && $method === 'POST' => RideController::resume((int)$m[1]),
        preg_match('#^/api/rides/(\d+)/retry-report$#', $uri, $m) && $method === 'POST' => RideController::retryReport((int)$m[1]),
        preg_match('#^/api/rides/(\d+)/locations$#', $uri, $m) && $method === 'GET'  => RideController::locations((int)$m[1]),

        $uri === '/api/telegram/settings' && $method === 'GET'    => TelegramController::getSettings(),
        $uri === '/api/telegram/settings' && $method === 'POST'   => TelegramController::saveSettings(),
        $uri === '/api/telegram/settings' && $method === 'DELETE' => TelegramController::deleteSettings(),
        $uri === '/api/telegram/test'     && $method === 'POST'   => TelegramController::test(),

        default => (function() {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found.']]);
        })(),
    };
    exit;
}

// ── PAGE ROUTES ────────────────────────────────────────────────────────────────
$page = match ($uri) {
    '/', '/login'    => 'login',
    '/register'      => 'register',
    '/dashboard'     => 'dashboard',
    '/settings'      => 'settings',
    '/settings/telegram'  => 'settings_telegram',
    '/settings/password'  => 'settings_password',
    '/logout'        => 'logout_action',
    default          => preg_match('#^/ride/(\d+)$#', $uri, $m) ? 'ride_detail' : '404',
};

if ($page === 'logout_action') {
    if ($method === 'POST') {
        CSRF::verifyRequest();
    }
    Auth::logout();
    header('Location: /login');
    exit;
}

if (in_array($page, ['dashboard', 'settings', 'settings_telegram', 'settings_password', 'ride_detail'])) {
    Auth::requireAuth();
}

if (in_array($page, ['login', 'register']) && Auth::isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}

$rideId = $m[1] ?? null;

// Render page
$csrfToken = CSRF::generate();
$username  = Auth::username();
renderPage($page, compact('csrfToken', 'username', 'rideId'));

// ──────────────────────────────────────────────────────────────────────────────

function renderPage(string $page, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $tplFile = __DIR__ . '/../templates/' . $page . '.php';
    if (!file_exists($tplFile)) {
        http_response_code(404);
        $tplFile = __DIR__ . '/../templates/404.php';
    }
    require __DIR__ . '/../templates/layout.php';
}
