<?php

declare(strict_types=1);

namespace Porter\Config;

class Config
{
    private static array $env = [];

    public static function load(): void
    {
        self::$env = [
            'app_env'                => getenv('APP_ENV') ?: 'production',
            'app_key'                => getenv('APP_KEY') ?: '',
            'app_url'                => getenv('APP_URL') ?: 'http://localhost',
            'database_url'           => getenv('DATABASE_URL') ?: '',
            'port'                   => (int)(getenv('PORT') ?: 8080),
            'log_level'              => getenv('LOG_LEVEL') ?: 'info',
            'track_interval_seconds' => (int)(getenv('TRACK_INTERVAL_SECONDS') ?: 30),
            'report_delay_seconds'   => (int)(getenv('REPORT_DELAY_SECONDS') ?: 600),
            'inactive_days'          => (int)(getenv('INACTIVE_DAYS') ?: 30),
            'timezone'               => getenv('APP_TIMEZONE') ?: 'Asia/Kolkata',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$env[$key] ?? $default;
    }

    public static function isDev(): bool
    {
        return self::get('app_env') === 'development';
    }
}
