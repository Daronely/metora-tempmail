<?php
/**
 * QR scan-to-login.
 *   GET  (authed)  → mint a fresh single-use token for the account owner's QR
 *   (login happens via POST /api/auth.php action=qr_login from the phone)
 */
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('method not allowed', 405);

/* ensure v6 tables exist even if setup/migration has not been re-run */
try {
    db()->exec("CREATE TABLE IF NOT EXISTS qr_tokens (
      token CHAR(64) PRIMARY KEY,
      account_id INT UNSIGNED NOT NULL,
      used TINYINT(1) NOT NULL DEFAULT 0,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_exp (expires_at),
      FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* exists */ }

$token = bin2hex(random_bytes(32));
db()->prepare("INSERT INTO qr_tokens (token, account_id, expires_at)
               VALUES (?,?, NOW() + INTERVAL 10 MINUTE)")
    ->execute([$token, $u['id']]);

json_out(['ok' => true, 'token' => $token,
          'url' => APP_URL . '/?qr=' . $token,
          'expires_in' => 600]);
