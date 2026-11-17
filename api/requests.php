<?php
/**
 * Resource requests — a user asks an admin for MORE quota for free.
 *
 *   GET            → my requests (last 20)
 *   POST           → create a request {kind, amount, reason}
 *                    kind: send | inbox | att | premium
 *   DELETE ?id=    → withdraw a pending request of mine
 *
 * Admin-side handling (approve/reject with per-user overrides) lives in
 * api/admin.php actions: requests / request_decide / set_overrides.
 */
require_once __DIR__ . '/../includes/helpers.php';

$u = require_user();
$pdo = db();

/* ensure v6 table exists even without re-running setup */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS resource_requests (
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
} catch (Throwable $e) { /* exists */ }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT id, kind, amount, reason, status, admin_note, created_at, handled_at
                           FROM resource_requests WHERE account_id = ?
                           ORDER BY id DESC LIMIT 20");
    $stmt->execute([$u['id']]);
    json_out(['ok' => true, 'requests' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM resource_requests
                           WHERE id=? AND account_id=? AND status='pending'");
    $stmt->execute([$id, $u['id']]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM resource_requests WHERE id=?")->execute([$id]);
        json_out(['ok' => true]);
    }
    json_error('درخواست یافت نشد یا قبلاً بررسی شده');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $kind = $in['kind'] ?? '';
    $amount = (int)($in['amount'] ?? 0);
    $reason = trim((string)($in['reason'] ?? ''));

    if (!in_array($kind, ['send', 'inbox', 'att', 'premium'], true))
        json_error('نوع درخواست نامعتبر است');
    if ($kind !== 'premium' && ($amount < 1 || $amount > 100000))
        json_error('مقدار درخواست باید بین ۱ تا ۱۰۰۰۰۰ باشد');
    if (mb_strlen($reason) < 10) json_error('دلیل درخواست را کامل‌تر بنویس (حداقل ۱۰ کاراکتر) — درخواست‌های بی‌دلیل رد می‌شوند');
    if (mb_strlen($reason) > 2000) json_error('دلیل درخواست خیلی طولانی است');

    // throttle: max 1 pending of each kind, max 3 pending total, max 5/day
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM resource_requests
                           WHERE account_id=? AND status='pending' AND kind=?");
    $stmt->execute([$u['id'], $kind]);
    if ((int)$stmt->fetch()['c'] > 0) json_error('برای این نوع درخواست، یک درخواست در انتظار بررسی دارید');

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM resource_requests
                           WHERE account_id=? AND status='pending'");
    $stmt->execute([$u['id']]);
    if ((int)$stmt->fetch()['c'] >= 3) json_error('حداکثر ۳ درخواست در انتظار — تا بررسی شدن صبر کنید');

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM resource_requests
                           WHERE account_id=? AND created_at > NOW() - INTERVAL 1 DAY");
    $stmt->execute([$u['id']]);
    if ((int)$stmt->fetch()['c'] >= 5) json_error('حداکثر ۵ درخواست در روز — فردا دوباره تلاش کنید');

    $pdo->prepare("INSERT INTO resource_requests (account_id, kind, amount, reason)
                   VALUES (?,?,?,?)")
        ->execute([$u['id'], $kind, $kind === 'premium' ? 0 : $amount, $reason]);

    json_out(['ok' => true]);
}

json_error('method not allowed', 405);
