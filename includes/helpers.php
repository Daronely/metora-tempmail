<?php
require_once __DIR__ . '/db.php';

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_out(['ok' => false, 'error' => $msg], $code);
}

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array {
    session_boot();
    if (empty($_SESSION['account_id'])) return null;
    $stmt = db()->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->execute([$_SESSION['account_id']]);
    $u = $stmt->fetch();
    if (!$u) { session_destroy(); return null; }
    return $u;
}

function require_user(): array {
    $u = current_user();
    if (!$u) json_error('unauthorized', 401);
    touch_last_active($u['id']);
    return $u;
}

function require_admin(): array {
    $u = require_user();
    if ($u['role'] !== 'admin') json_error('forbidden', 403);
    // every admin MUTATION must carry a valid CSRF header (GET stays open)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();
    return $u;
}

function touch_last_active(int $id): void {
    db()->prepare("UPDATE accounts SET last_active_at = NOW() WHERE id = ?")->execute([$id]);
}

function is_premium(array $u): bool {
    if ($u['role'] === 'admin') return true;
    if ((int)$u['is_premium'] !== 1) return false;
    if ($u['premium_until'] !== null && strtotime($u['premium_until']) < time()) return false;
    return true;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function valid_username(string $u): bool {
    return (bool)preg_match('/^[a-z0-9]([a-z0-9._-]{1,30})[a-z0-9]$/i', $u);
}

function valid_email(string $e): bool {
    return filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 254;
}

function send_quota_used(int $account_id): int {
    $stmt = db()->prepare("SELECT COUNT(*) c FROM send_log WHERE account_id = ? AND cycle_id = ?");
    $stmt->execute([$account_id, current_cycle_id()]);
    return (int)$stmt->fetch()['c'];
}

/** unlimited = true means quotas never apply */
function is_unlimited(array $u): bool {
    return $u['role'] === 'admin' && get_setting('admin_unlimited', '1') === '1';
}

/* ── quota resolution: per-user admin override wins over plan default ── */
function user_override(array $u, string $col): ?int {
    $v = $u[$col] ?? null;
    return ($v === null || $v === '' || (int)$v === 0) ? null : (int)$v;
}

function send_quota_limit(array $u): int {
    if (is_unlimited($u)) return PHP_INT_MAX;
    if ($u['role'] === 'admin') return (int)get_setting('send_limit_admin', 100000);
    if ($ov = user_override($u, 'override_send_limit')) return $ov;
    return is_premium($u)
        ? (int)get_setting('send_limit_premium', SEND_LIMIT_PREMIUM)
        : (int)get_setting('send_limit_free', SEND_LIMIT_FREE);
}

function inbox_limit(array $u): int {
    if (is_unlimited($u)) return PHP_INT_MAX;
    if ($u['role'] === 'admin') return (int)get_setting('inbox_limit_admin', 100000);
    if ($ov = user_override($u, 'override_inbox_limit')) return $ov;
    return is_premium($u)
        ? (int)get_setting('inbox_limit_premium', INBOX_LIMIT_PREMIUM)
        : (int)get_setting('inbox_limit_free', INBOX_LIMIT_FREE);
}

function att_limit(array $u): int {
    if (is_unlimited($u)) return (int)get_setting('att_limit_admin', 10);
    if ($ov = user_override($u, 'override_att_limit')) return $ov;
    return is_premium($u)
        ? (int)get_setting('att_limit_premium', ATTACH_LIMIT_PREMIUM)
        : (int)get_setting('att_limit_free', ATTACH_LIMIT_FREE);
}

/* ── language: session value → account preference → default ── */
const SUPPORTED_LANGS = ['en' => 'ltr', 'fa' => 'rtl', 'ru' => 'ltr'];
function normalize_lang(?string $l): string {
    return isset(SUPPORTED_LANGS[$l]) ? $l : 'en';
}
function current_lang(): string {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['lang']))
        return normalize_lang($_SESSION['lang']);
    return 'en';
}

/* ── CSRF token (double-submit cookie pattern, no storage needed) ── */
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
/** verify the X-CSRF header against the session token (admin mutations) */
function csrf_check(): void {
    session_boot();
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_error('نشست شما منقضی شده — صفحه را رفرش کنید', 403);
    }
}

/** human-friendly quota number for JSON output (null = unlimited) */
function quota_display(int $limit): ?int {
    return $limit >= PHP_INT_MAX ? null : $limit;
}

/** store an uploaded file in STORAGE_DIR, return relative path */
function store_attachment(array $file): array {
    if (!is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('invalid upload');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('upload error ' . $file['error']);
    if ($file['size'] > MAX_UPLOAD_BYTES) throw new RuntimeException('file too large');
    $dir = STORAGE_DIR . '/att';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $name = preg_replace('/[^\w.\- ]/u', '_', $file['name'] ?: 'file');
    $rel = 'att/' . bin2hex(random_bytes(12)) . '-' . $name;
    if (!move_uploaded_file($file['tmp_name'], STORAGE_DIR . '/' . $rel)
        && !rename($file['tmp_name'], STORAGE_DIR . '/' . $rel)) {
        throw new RuntimeException('store failed');
    }
    return [$name, $rel, (int)$file['size']];
}
