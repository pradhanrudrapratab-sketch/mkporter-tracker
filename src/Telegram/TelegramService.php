<?php

declare(strict_types=1);

namespace Porter\Telegram;

use Porter\Config\Logger;

class TelegramService
{
    private const API_BASE = 'https://api.telegram.org/bot';
    private const TIMEOUT  = 15;

    public static function sendMessage(string $token, string $chatId, string $text): array
    {
        return self::apiCall($token, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    public static function sendDocument(string $token, string $chatId, string $filename, string $content, string $caption = ''): array
    {
        $url = self::API_BASE . $token . '/sendDocument';
        $ch  = curl_init($url);

        $boundary = uniqid('', true);
        $body     = self::buildMultipart($boundary, [
            ['name' => 'chat_id',  'value' => $chatId],
            ['name' => 'caption',  'value' => $caption],
            ['name' => 'document', 'value' => $content, 'filename' => $filename, 'type' => 'text/csv'],
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ["Content-Type: multipart/form-data; boundary={$boundary}"],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            return ['success' => false, 'error' => 'TELEGRAM_HTTP'];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || !($data['ok'] ?? false)) {
            Logger::warn('Telegram', 'send_document_failed', ['description' => $data['description'] ?? 'unknown']);
            return ['success' => false, 'error' => $data['description'] ?? 'Telegram API error.'];
        }

        return ['success' => true];
    }

    private static function apiCall(string $token, string $method, array $payload): array
    {
        $url = self::API_BASE . $token . '/' . $method;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            return ['success' => false, 'error' => 'TELEGRAM_HTTP'];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || !($data['ok'] ?? false)) {
            return ['success' => false, 'error' => $data['description'] ?? 'Telegram API error.'];
        }

        return ['success' => true, 'result' => $data['result'] ?? null];
    }

    private static function buildMultipart(string $boundary, array $fields): string
    {
        $body = '';
        foreach ($fields as $field) {
            $body .= "--{$boundary}\r\n";
            if (isset($field['filename'])) {
                $body .= "Content-Disposition: form-data; name=\"{$field['name']}\"; filename=\"{$field['filename']}\"\r\n";
                $body .= "Content-Type: {$field['type']}\r\n\r\n";
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$field['name']}\"\r\n\r\n";
            }
            $body .= $field['value'] . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        return $body;
    }
}
