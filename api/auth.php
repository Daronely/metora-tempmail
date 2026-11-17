<?php
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('method not allowed', 405);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

/* ── brute-force guard (file-based, works on shared hosting) ──
   10 failed attempts per email or per IP within 15 minutes → lockout.
   Successful logins reset the counter. */
function rl_key(string $k): string { return sys_get_temp_dir() . '/tm_rl_' . md5($k); }
function rl_fail(string $k, int $max = 10, int $window = 900): array {
    $f = rl_key($k);
    $now = time();
    $data = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : ['t' => 0, 'n' => 0];
    if ($now - (int)$data['t'] > $window) { $data = ['t' => $now, 'n' => 0]; }
    $data['n']++; $data['t'] = $now;
    @file_put_contents($f, json_encode($data), LOCK_EX);
    $left = $max - $data['n'];
    return ['locked' => $data['n'] > $max, 'left' => max(0, $left)];
}
function rl_ok(string $k): void { @unlink(rl_key($k)); }
function rl_try(string $k, int $max = 10): void {
    $f = rl_key($k);
    if (!is_file($f)) return;
    $data = json_decode((string)file_get_contents($f), true) ?: [];
    if ((int)($data['n'] ?? 0) > $max && time() - (int)($data['t'] ?? 0) <= 900) {
        json_error('تلاش‌های ناموفق زیاد — ۱۵ دقیقه صبر کنید', 429);
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($action === 'register') {
    $username = strtolower(trim($in['username'] ?? ''));
    $password = $in['password'] ?? '';
    if (!valid_username($username)) json_error('نام کاربری باید ۳ تا ۳۲ کاراکتر انگلیسی و شامل حروف/عدد/. _ - باشد');
    if (strlen($password) < 8) json_error('رمز عبور باید حداقل ۸ کاراکتر باشد');
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
        json_error('رمز عبور باید شامل حرف و عدد باشد');

    // rate-limit registrations per IP (20/hour)
    $rg = rl_fail('reg:' . $ip, 20, 3600);
    if ($rg['locked']) json_error('ثبت‌نام‌های زیاد از این IP — کمی بعد تلاش کنید', 429);

    $email = $username . '@' . MAIL_DOMAIN;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) json_error('این نام کاربری قبلاً ثبت شده است');

    $pdo->prepare("INSERT INTO accounts (username, email, password_hash, role, is_premium)
                   VALUES (?,?,?, 'user', 0)")
        ->execute([$username, $email,
                   password_hash($password . PEPPER, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])]);
    $id = (int)$pdo->lastInsertId();

    session_boot();
    session_regenerate_id(true);
    $_SESSION['account_id'] = $id;
    $_SESSION['lang'] = $in['lang'] ?? 'en';
    json_out(['ok' => true, 'email' => $email]);
}

if ($action === 'login') {
    $email = strtolower(trim($in['email'] ?? ''));
    $password = $in['password'] ?? '';
    if (!valid_email($email)) json_error('ایمیل نامعتبر است');

    // check lock BEFORE verifying (cheap) — per email AND per IP
    rl_try('login:' . $email);
    rl_try('login:ip:' . $ip);

    $stmt = db()->prepare("SELECT * FROM accounts WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password . PEPPER, $u['password_hash'])) {
        $a = rl_fail('login:' . $email);
        $b = rl_fail('login:ip:' . $ip);
        $left = min($a['left'], $b['left']);
        json_error('ایمیل یا رمز عبور اشتباه است' . ($left <= 3 ? " — $left تلاش باقی مانده" : ''), 401);
    }
    rl_ok('login:' . $email); rl_ok('login:ip:' . $ip);

    session_boot();
    session_regenerate_id(true);
    $_SESSION['account_id'] = (int)$u['id'];
    if (!empty($in['lang'])) $_SESSION['lang'] = $in['lang'];
    touch_last_active((int)$u['id']);
    json_out(['ok' => true, 'email' => $u['email'], 'role' => $u['role'], 'premium' => is_premium($u)]);
}

/* QR auto-login: token created by an authenticated session owner, consumed by the phone.
   Tokens are single-use, 10-minute TTL, bound to the account. */
if ($action === 'qr_login') {
    $token = trim($in['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) json_error('توکن نامعتبر است', 422);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT qt.*, a.email, a.role, a.is_premium, a.premium_until
                           FROM qr_tokens qt JOIN accounts a ON a.id = qt.account_id
                           WHERE qt.token = ? AND qt.used = 0 AND qt.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) json_error('این QR منقضی شده یا استفاده شده — از دسکتاپ QR جدید بگیر', 401);
    $pdo->prepare("UPDATE qr_tokens SET used = 1 WHERE token = ?")->execute([$token]);

    session_boot();
    session_regenerate_id(true);
    $_SESSION['account_id'] = (int)$row['account_id'];
    if (!empty($in['lang'])) $_SESSION['lang'] = $in['lang'];
    touch_last_active((int)$row['account_id']);
    json_out(['ok' => true, 'email' => $row['email'], 'role' => $row['role'],
              'premium' => is_premium($row)]);
}

json_error('unknown action', 422);
