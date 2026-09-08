<?php

declare(strict_types=1);

namespace Porter\Config;

class Encryption
{
    private static string $cipher = 'aes-256-gcm';
    private static int $tagLength = 16;

    private static function getKey(): string
    {
        $key = Config::get('app_key');
        if (empty($key)) {
            throw new \RuntimeException('APP_KEY is not configured.');
        }
        return hash('sha256', $key, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $key    = self::getKey();
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plaintext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::$tagLength);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $key  = self::getKey();
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 12 + self::$tagLength) {
            throw new \RuntimeException('Decryption failed: invalid data.');
        }
        $iv     = substr($data, 0, 12);
        $tag    = substr($data, 12, self::$tagLength);
        $cipher = substr($data, 12 + self::$tagLength);
        $plain  = openssl_decrypt($cipher, self::$cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }
        return $plain;
    }
}
