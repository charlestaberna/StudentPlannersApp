<?php
// ─────────────────────────────────────────────
//  Session bootstrap + auth guard
//  include this at the very top of every protected page
// ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['sp_user'])) {
    header("Location: login.php");
    exit;
}

$user    = $_SESSION['sp_user'];
$userId  = intval($user['id']);
$isAdmin = ($user['role'] === 'admin');

// Subjects / Tasks / Notes / Calendar are shared, school-wide content now —
// same idea as the class schedule: admins and faculty ("staff") manage it,
// and everyone else (students) only gets to view it. No add/edit/delete
// controls are rendered for students, and every page blocks the underlying
// POST actions server-side too, so a student can't just POST directly to
// the page and bypass the UI.
$canManage = $isAdmin || ($user['role'] === 'faculty');

// self-heal: make sure the avatar_path column exists even on older installs
function columnExists($conn, $table, $col){
    $table = mysqli_real_escape_string($conn, $table);
    $col   = mysqli_real_escape_string($conn, $col);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $res && mysqli_num_rows($res) > 0;
}
if (!columnExists($conn, 'login_accounts', 'avatar_path')) {
    mysqli_query($conn, "ALTER TABLE login_accounts ADD COLUMN avatar_path VARCHAR(255) NULL AFTER theme_color");
}

// keep "online" status fresh
mysqli_query($conn, "UPDATE login_accounts SET last_seen = NOW(), is_online = 1 WHERE id = $userId");
mysqli_query($conn, "UPDATE login_accounts SET is_online = 0 WHERE last_seen < DATE_SUB(NOW(), INTERVAL 3 MINUTE)");

// make sure the account wasn't removed / unapproved meanwhile
$chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, is_approved, name, role, avatar_path FROM login_accounts WHERE id=$userId"));
if (!$chk || !$chk['is_approved']) {
    session_destroy();
    header("Location: login.php?kicked=1");
    exit;
}
// keep session name/role/avatar in sync with DB — this is what makes the
// profile picture survive refresh / re-login: it's always re-read from the
// account row, never just kept in a browser-side value.
$_SESSION['sp_user']['name']        = $chk['name'];
$_SESSION['sp_user']['role']        = $chk['role'];
$_SESSION['sp_user']['avatar_path'] = $chk['avatar_path'];
$user = $_SESSION['sp_user'];
$isAdmin = ($user['role'] === 'admin');
$canManage = $isAdmin || ($user['role'] === 'faculty');

// Renders a user's profile picture if they have one uploaded, otherwise
// falls back to the initials avatar so every screen looks consistent.
function renderAvatar($name, $avatarPath, $class = ''){
    if (!empty($avatarPath) && file_exists(__DIR__ . '/../' . $avatarPath)) {
        return '<img src="' . h($avatarPath) . '?v=' . filemtime(__DIR__ . '/../' . $avatarPath) . '" class="' . h($class) . ' avatar-img" alt="">';
    }
    return '<div class="' . h($class) . '">' . h(initials($name)) . '</div>';
}

function initials($name){
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts,0,2) as $p) { if ($p !== '') $ini .= mb_strtoupper(mb_substr($p,0,1)); }
    return $ini ?: 'S';
}

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
