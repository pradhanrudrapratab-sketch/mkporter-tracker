<?php

declare(strict_types=1);

namespace Porter\Config;

class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    public static function log(string $level, string $component, string $event, array $context = []): void
    {
        $configLevel = Config::get('log_level', 'info');
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$configLevel] ?? 1)) {
            return;
        }

        // Never log sensitive fields
        $safe = array_diff_key($context, array_flip([
            'password', 'password_hash', 'bot_token', 'bot_token_encrypted', 'tracking_url'
        ]));

        $entry = json_encode([
            'ts'        => date('c'),
            'level'     => $level,
            'component' => $component,
            'event'     => $event,
            'context'   => $safe,
        ]);

        error_log($entry);
    }

    public static function info(string $component, string $event, array $ctx = []): void
    {
        self::log('info', $component, $event, $ctx);
    }

    public static function warn(string $component, string $event, array $ctx = []): void
    {
        self::log('warn', $component, $event, $ctx);
    }

    public static function error(string $component, string $event, array $ctx = []): void
    {
        self::log('error', $component, $event, $ctx);
    }

    public static function debug(string $component, string $event, array $ctx = []): void
    {
        self::log('debug', $component, $event, $ctx);
    }
}
