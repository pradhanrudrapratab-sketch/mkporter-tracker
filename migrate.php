<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Porter\Config\Config;
use Porter\Config\Database;

Config::load();

$pdo = Database::getInstance();

// Create migrations tracking table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(128) PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
");

$migrationDir = __DIR__ . '/database/migrations';
$files        = glob($migrationDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $filename = basename($file);

    $exists = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
    $exists->execute([$filename]);

    if ($exists->fetch()) {
        echo "[SKIP] {$filename}\n";
        continue;
    }

    $sql = file_get_contents($file);
    echo "[APPLY] {$filename}...\n";

    try {
        $pdo->exec($sql);
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$filename]);
        echo "[OK]   {$filename}\n";
    } catch (\Exception $e) {
        echo "[FAIL] {$filename}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\nMigrations complete.\n";
