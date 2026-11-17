<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function db_install(): void {
    $pdo = db();
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS accounts (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(64) NOT NULL UNIQUE,
      email VARCHAR(128) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role ENUM('user','admin') NOT NULL DEFAULT 'user',
      is_premium TINYINT(1) NOT NULL DEFAULT 0,
      premium_until DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_last_active (last_active_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS messages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      account_id INT UNSIGNED NOT NULL,
      folder ENUM('inbox','sent') NOT NULL DEFAULT 'inbox',
      from_addr VARCHAR(255) NOT NULL,
      to_addr VARCHAR(1024) NOT NULL,
      subject VARCHAR(512) NOT NULL DEFAULT '',
      body_text MEDIUMTEXT,
      body_html MEDIUMTEXT,
      size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      dedupe_key CHAR(64) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_acc_folder (account_id, folder, created_at),
      INDEX idx_dedupe (account_id, folder, dedupe_key),
      FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS attachments (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      message_id INT UNSIGNED NOT NULL,
      filename VARCHAR(255) NOT NULL,
      mime VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
      size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
      file_path VARCHAR(512) NOT NULL,
      INDEX idx_msg (message_id),
      FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS cycles (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS send_log (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      account_id INT UNSIGNED NOT NULL,
      cycle_id INT UNSIGNED NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_acc_cycle (account_id, cycle_id),
      FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
      k VARCHAR(64) PRIMARY KEY,
      v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS scheduled_messages (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      account_id INT UNSIGNED NOT NULL,
      to_addr VARCHAR(255) NOT NULL,
      subject VARCHAR(512) NOT NULL DEFAULT '',
      body_text MEDIUMTEXT,
      att_json TEXT,
      send_at DATETIME NOT NULL,
      status ENUM('pending','sent','failed','canceled') NOT NULL DEFAULT 'pending',
      sent_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_status_send (status, send_at),
      INDEX idx_acc (account_id),
      FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // seed settings (editable from admin panel; config constants are fallback defaults)
    $defaults = [
        'send_limit_free'    => defined('SEND_LIMIT_FREE') ? SEND_LIMIT_FREE : 10,
        'send_limit_premium' => defined('SEND_LIMIT_PREMIUM') ? SEND_LIMIT_PREMIUM : 100,
        'send_limit_admin'   => 100000,
        'inbox_limit_free'   => defined('INBOX_LIMIT_FREE') ? INBOX_LIMIT_FREE : 50,
        'inbox_limit_premium' => defined('INBOX_LIMIT_PREMIUM') ? INBOX_LIMIT_PREMIUM : 500,
        'inbox_limit_admin'  => 100000,
        'att_limit_free'     => defined('ATTACH_LIMIT_FREE') ? ATTACH_LIMIT_FREE : 1,
        'att_limit_premium'  => defined('ATTACH_LIMIT_PREMIUM') ? ATTACH_LIMIT_PREMIUM : 5,
        'att_limit_admin'    => 10,
        'premium_price'      => defined('PREMIUM_PRICE') ? PREMIUM_PRICE : 10,
    ];
    $seed = $pdo->prepare("INSERT IGNORE INTO settings (k, v) VALUES (?,?)");
    foreach ($defaults as $k => $v) $seed->execute([$k, (string)$v]);

    // seed admin account (password is the seed and must be changed)
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO accounts (username, email, password_hash, role, is_premium)
                       VALUES (?,?,?,?,1)")
            ->execute([explode('@', ADMIN_EMAIL)[0], ADMIN_EMAIL,
                       password_hash(ADMIN_SEED_PASSWORD . PEPPER, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
                       'admin']);
    }
    // seed first cycle only if table empty
    if (!(int)$pdo->query("SELECT COUNT(*) c FROM cycles")->fetch()['c']) {
        $pdo->exec("INSERT INTO cycles (started_at) VALUES (NOW())");
    }
}

/** fetch a runtime setting with fallback */
function get_setting(string $k, $fallback = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT k, v FROM settings") as $r) $cache[$r['k']] = $r['v'];
        } catch (Throwable $e) { /* settings table missing → use fallbacks */ }
    }
    return isset($cache[$k]) ? $cache[$k] : $fallback;
}

function set_setting(string $k, $v): void {
    db()->prepare("INSERT INTO settings (k, v) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, (string)$v]);
}

function current_cycle_id(): int {
    $row = db()->query("SELECT id FROM cycles ORDER BY id DESC LIMIT 1")->fetch();
    return $row ? (int)$row['id'] : 1;
}

/**
 * v6 migration: run once (idempotent). Adds columns/tables introduced
 * after the first release without requiring setup.php re-run:
 *   - messages.dedupe_key (+ index)      → real Message-ID dedupe
 *   - qr_tokens                          → scan-to-login
 *   - resource_requests                  → users asking admins for more quota
 *   - accounts.lang + quota overrides    → per-user language + custom limits
 */
function db_migrate(): void {
    $pdo = db();
    // ── messages.dedupe_key ──
    try {
        $pdo->exec("ALTER TABLE messages ADD COLUMN dedupe_key CHAR(64) NULL,
                    ADD INDEX idx_dedupe (account_id, folder, dedupe_key)");
    } catch (Throwable $e) { /* already exists */ }
    // ── accounts: language + per-user quota overrides (NULL = use plan default) ──
    foreach (['lang' => "VARCHAR(8) NULL",
              'override_send_limit' => 'INT UNSIGNED NULL',
              'override_inbox_limit' => 'INT UNSIGNED NULL',
              'override_att_limit' => 'INT UNSIGNED NULL'] as $col => $def) {
        try { $pdo->exec("ALTER TABLE accounts ADD COLUMN $col $def"); }
        catch (Throwable $e) { /* already exists */ }
    }
    // ── qr_tokens: single-use, 10-minute scan-to-login ──
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS qr_tokens (
      token CHAR(64) PRIMARY KEY,
      account_id INT UNSIGNED NOT NULL,
      used TINYINT(1) NOT NULL DEFAULT 0,
      expires_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_exp (expires_at),
      FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // ── resource_requests: user → admin upgrade requests ──
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS resource_requests (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      account_id INT UNSIGNED NOT NULL,
      kind ENUM('send','inbox','att','premium') NOT NULL,
      amount INT UNSIGNED NOT NULL DEFAULT 0,
      reason TEXT NOT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      admin_note TEXT NULL,
      handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_status (status, created_at),
      INDEX idx_acc (account_id),
      FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // housekeeping: expire stale QR tokens (kept 24h for audit)
    try { $pdo->exec("DELETE FROM qr_tokens WHERE expires_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable $e) {}
}
