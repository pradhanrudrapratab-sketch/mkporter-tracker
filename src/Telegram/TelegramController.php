<?php

declare(strict_types=1);

namespace Porter\Telegram;

use Porter\Config\Database;
use Porter\Config\Encryption;
use Porter\Config\Logger;
use Porter\Middleware\Auth;
use Porter\Middleware\CSRF;

class TelegramController
{
    public static function getSettings(): void
    {
        Auth::requireAuth();
        $pdo    = Database::getInstance();
        $stmt   = $pdo->prepare('SELECT chat_id, enabled, updated_at FROM telegram_settings WHERE user_id = ?');
        $stmt->execute([Auth::userId()]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['success' => true, 'data' => null]);
            return;
        }

        // Never send bot_token back to browser - show masked version only
        $stmtToken = $pdo->prepare('SELECT bot_token_encrypted FROM telegram_settings WHERE user_id = ?');
        $stmtToken->execute([Auth::userId()]);
        $tokenRow = $stmtToken->fetch();
        $masked = null;
        if ($tokenRow && !empty($tokenRow['bot_token_encrypted'])) {
            try {
                $plain  = Encryption::decrypt($tokenRow['bot_token_encrypted']);
                $masked = '••••••••' . substr($plain, -4);
            } catch (\Exception) {
                $masked = '••••••••';
            }
        }

        echo json_encode(['success' => true, 'data' => [
            'chat_id'      => $row['chat_id'],
            'enabled'      => (bool)$row['enabled'],
            'token_masked' => $masked,
            'updated_at'   => $row['updated_at'],
        ]]);
    }

    public static function saveSettings(): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();

        $botToken = trim($_POST['bot_token'] ?? '');
        $chatId   = trim($_POST['chat_id'] ?? '');
        $enabled  = !empty($_POST['enabled']);
        $userId   = Auth::userId();

        if (empty($botToken) || empty($chatId)) {
            self::jsonError('VALIDATION_ERROR', 'Bot Token and Chat ID are required.');
        }

        // Basic token format validation
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{35,}$/', $botToken)) {
            self::jsonError('VALIDATION_ERROR', 'Bot Token format is invalid.');
        }

        $encrypted = Encryption::encrypt($botToken);
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT id FROM telegram_settings WHERE user_id = ?');
        $stmt->execute([$userId]);

        if ($stmt->fetch()) {
            $pdo->prepare('UPDATE telegram_settings SET bot_token_encrypted=?, chat_id=?, enabled=?, updated_at=NOW() WHERE user_id=?')
                ->execute([$encrypted, $chatId, $enabled, $userId]);
        } else {
            $pdo->prepare('INSERT INTO telegram_settings (user_id, bot_token_encrypted, chat_id, enabled) VALUES (?,?,?,?)')
                ->execute([$userId, $encrypted, $chatId, $enabled]);
        }

        Logger::info('Telegram', 'settings_saved', ['user_id' => $userId]);
        echo json_encode(['success' => true, 'message' => 'Telegram settings saved.']);
    }

    public static function deleteSettings(): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();

        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM telegram_settings WHERE user_id = ?')->execute([Auth::userId()]);
        echo json_encode(['success' => true, 'message' => 'Telegram settings deleted.']);
    }

    public static function test(): void
    {
        Auth::requireAuth();
        CSRF::verifyRequest();

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT bot_token_encrypted, chat_id FROM telegram_settings WHERE user_id = ? AND enabled = true');
        $stmt->execute([Auth::userId()]);
        $row = $stmt->fetch();

        if (!$row) {
            self::jsonError('TELEGRAM_CONFIG', 'Telegram is not configured or not enabled.');
        }

        try {
            $token   = Encryption::decrypt($row['bot_token_encrypted']);
            $chatId  = $row['chat_id'];
            $result  = TelegramService::sendMessage($token, $chatId, '✅ Porter Tracker Telegram test successful.');

            if ($result['success']) {
                echo json_encode(['success' => true, 'message' => 'Test message sent successfully.']);
            } else {
                self::jsonError('TELEGRAM_SEND', $result['error'] ?? 'Telegram API request failed.');
            }
        } catch (\Exception $e) {
            Logger::error('Telegram', 'test_failed', ['error' => $e->getMessage()]);
            self::jsonError('TELEGRAM_API', 'Telegram configuration is invalid.');
        }
    }

    private static function jsonError(string $code, string $message): never
    {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
        exit;
    }
}
