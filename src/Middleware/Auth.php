<?php

declare(strict_types=1);

namespace Porter\Middleware;

use Porter\Config\Database;
use Porter\Config\Logger;

class Auth
{
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function requireAuth(): void
    {
        if (!self::isLoggedIn()) {
            if (self::isApiRequest()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Authentication required.']]);
                exit;
            }
            header('Location: /login');
            exit;
        }
        // Update last_active_at for human activity
        self::touchActivity();
    }

    public static function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function username(): string
    {
        return $_SESSION['username'] ?? '';
    }

    public static function login(int $userId, string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $userId;
        $_SESSION['username'] = $username;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    private static function touchActivity(): void
    {
        // Only touch if it's been more than 5 minutes since last touch (avoid DB spam)
        if (!empty($_SESSION['last_touch']) && (time() - $_SESSION['last_touch']) < 300) {
            return;
        }
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?');
            $stmt->execute([self::userId()]);
            $_SESSION['last_touch'] = time();
        } catch (\Exception $e) {
            Logger::warn('Auth', 'touch_activity_failed', ['error' => $e->getMessage()]);
        }
    }

    private static function isApiRequest(): bool
    {
        return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    }
}
