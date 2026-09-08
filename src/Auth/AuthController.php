<?php

declare(strict_types=1);

namespace Porter\Auth;

use Porter\Config\Database;
use Porter\Config\Logger;
use Porter\Middleware\Auth;
use Porter\Middleware\CSRF;

class AuthController
{
    public static function register(): void
    {
        CSRF::verifyRequest();

        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        // Normalize username to lowercase
        $username = strtolower($username);

        // Validation
        $errors = [];
        if (!preg_match('/^[a-z0-9_-]{3,32}$/', $username)) {
            $errors[] = 'Username must be 3–32 characters: letters, numbers, underscore, hyphen only.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            self::jsonError('VALIDATION_ERROR', implode(' ', $errors));
        }

        $pdo = Database::getInstance();

        // Check uniqueness
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            self::jsonError('VALIDATION_ERROR', 'Username is already taken.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, last_active_at) VALUES (?, ?, NOW()) RETURNING id');
        $stmt->execute([$username, $hash]);
        $row = $stmt->fetch();

        Logger::info('Auth', 'user_registered', ['user_id' => $row['id']]);

        Auth::login((int)$row['id'], $username);
        echo json_encode(['success' => true, 'redirect' => '/dashboard']);
    }

    public static function login(): void
    {
        CSRF::verifyRequest();

        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Constant-time check even if user not found
        $hash = $user['password_hash'] ?? '$2y$10$invalidhashpadding00000000000000000000000000000000000000';
        if (!$user || !password_verify($password, $hash)) {
            // Generic message - don't reveal if username exists
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => ['code' => 'AUTH_INVALID', 'message' => 'Invalid username or password.']]);
            return;
        }

        // Update last_active_at on login
        $pdo->prepare('UPDATE users SET last_active_at = NOW(), last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        Auth::login((int)$user['id'], $username);
        Logger::info('Auth', 'user_logged_in', ['user_id' => $user['id']]);

        echo json_encode(['success' => true, 'redirect' => '/dashboard']);
    }

    public static function logout(): void
    {
        CSRF::verifyRequest();
        Logger::info('Auth', 'user_logged_out', ['user_id' => Auth::userId()]);
        Auth::logout();
        echo json_encode(['success' => true, 'redirect' => '/login']);
    }

    public static function changePassword(): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();

        $oldPassword  = $_POST['current_password'] ?? '';
        $newPassword  = $_POST['new_password'] ?? '';
        $confirmNew   = $_POST['confirm_new_password'] ?? '';

        $errors = [];
        if (empty($oldPassword)) {
            $errors[] = 'Current password is required.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($newPassword !== $confirmNew) {
            $errors[] = 'New passwords do not match.';
        }

        if (!empty($errors)) {
            self::jsonError('VALIDATION_ERROR', implode(' ', $errors));
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([Auth::userId()]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            self::jsonError('AUTH_INVALID', 'Current password is incorrect.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$newHash, Auth::userId()]);

        Logger::info('Auth', 'password_changed', ['user_id' => Auth::userId()]);
        echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
    }

    private static function jsonError(string $code, string $message): never
    {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
        exit;
    }
}
