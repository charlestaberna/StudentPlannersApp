<?php
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['sp_user'])) {
    $id = intval($_SESSION['sp_user']['id']);
    mysqli_query($conn, "UPDATE login_accounts SET is_online = 0 WHERE id = $id");
}
session_destroy();
header("Location: login.php");
exit;
