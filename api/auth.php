<?php
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('method not allowed', 405);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

if ($action === 'register') {
    $username = strtolower(trim($in['username'] ?? ''));
    $password = $in['password'] ?? '';
    if (!valid_username($username)) json_error('نام کاربری باید ۳ تا ۳۲ کاراکتر انگلیسی و شامل حروف/عدد/. _ - باشد');
    if (strlen($password) < 6) json_error('رمز عبور باید حداقل ۶ کاراکتر باشد');

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
    json_out(['ok' => true, 'email' => $email]);
}

if ($action === 'login') {
    $email = strtolower(trim($in['email'] ?? ''));
    $password = $in['password'] ?? '';
    if (!valid_email($email)) json_error('ایمیل نامعتبر است');
    $stmt = db()->prepare("SELECT * FROM accounts WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password . PEPPER, $u['password_hash'])) {
        json_error('ایمیل یا رمز عبور اشتباه است', 401);
    }
    session_boot();
    session_regenerate_id(true);
    $_SESSION['account_id'] = (int)$u['id'];
    touch_last_active((int)$u['id']);
    json_out(['ok' => true, 'email' => $u['email'], 'role' => $u['role'], 'premium' => is_premium($u)]);
}

json_error('unknown action', 422);
