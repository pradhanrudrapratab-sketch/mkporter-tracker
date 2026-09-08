<?php

declare(strict_types=1);

namespace Porter\Config;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = Config::get('database_url');
            if (empty($dsn)) {
                throw new \RuntimeException('DATABASE_URL is not configured.');
            }

            // Parse postgres:// URL into PDO DSN
            $parsed = parse_url($dsn);
            if (!$parsed) {
                throw new \RuntimeException('Invalid DATABASE_URL format.');
            }

            $host     = $parsed['host'] ?? 'localhost';
            $port     = $parsed['port'] ?? 5432;
            $dbname   = ltrim($parsed['path'] ?? '/porter', '/');
            $user     = $parsed['user'] ?? '';
            $password = $parsed['pass'] ?? '';

            $pdoDsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

            try {
                self::$instance = new PDO($pdoDsn, $user, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$instance->exec("SET timezone = 'UTC'");
            } catch (PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
