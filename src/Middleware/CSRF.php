<?php

declare(strict_types=1);

namespace Porter\Middleware;

class CSRF
{
    private const TOKEN_KEY = '_csrf_token';

    public static function generate(): string
    {
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    public static function verify(string $token): bool
    {
        $stored = $_SESSION[self::TOKEN_KEY] ?? '';
        return hash_equals($stored, $token);
    }

    public static function verifyRequest(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!self::verify($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => ['code' => 'CSRF_INVALID', 'message' => 'Invalid request token.']]);
            exit;
        }
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::generate()) . '">';
    }
}
