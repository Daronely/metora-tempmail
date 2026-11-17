<?php
/** Persist the UI language into the session (and account when logged in). */
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('method not allowed', 405);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$lang = normalize_lang($in['lang'] ?? null);

session_boot();
$_SESSION['lang'] = $lang;

// logged-in users get it stored on the account too (next device = same language)
$u = current_user();
if ($u) {
    try {
        db()->prepare("ALTER TABLE accounts ADD COLUMN lang VARCHAR(8) NULL")->execute();
    } catch (Throwable $e) { /* column exists */ }
    db()->prepare("UPDATE accounts SET lang = ? WHERE id = ?")->execute([$lang, $u['id']]);
}

// echo back so the client can confirm + send dir for boot-time HTML flip
json_out(['ok' => true, 'lang' => $lang, 'dir' => SUPPORTED_LANGS[$lang]]);
