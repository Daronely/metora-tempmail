<?php
/**
 * Installer — visit /setup.php once, then DELETE this file.
 * Creates tables, seeds the admin account, creates storage/state dirs.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (php_sapi_name() === 'cli') {
    echo "CLI install\n";
    db_install();
    echo "OK — tables created, admin seeded: " . ADMIN_EMAIL . "\n";
    exit(0);
}

// only allow once (config gate)
$lock = STORAGE_DIR . '/.installed';
if (file_exists($lock)) {
    echo "Already installed. Delete storage/.installed to re-run (data will be kept).\n";
    exit;
}

if (!is_dir(STORAGE_DIR)) @mkdir(STORAGE_DIR . '/att', 0750, true);
if (!is_dir(STATE_DIR)) @mkdir(STATE_DIR, 0750, true);

try {
    db_install();
    file_put_contents($lock, date('c'));
    echo "INSTALL OK\n";
    echo "admin: " . ADMIN_EMAIL . "\n";
    echo "storage: " . STORAGE_DIR . "\n";
    echo "state:   " . STATE_DIR . "\n";
    echo "NOW DELETE setup.php\n";
} catch (Throwable $e) {
    echo "INSTALL FAILED: " . $e->getMessage() . "\n";
}
