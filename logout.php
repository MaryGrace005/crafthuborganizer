<?php
require_once __DIR__ . '/includes/header.php';

if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    $name   = $_SESSION['user_name'];
    logAudit($userId, 'LOGOUT', $name . ' logged out.', 'users');
}

logoutUser();
setFlash('info', 'You have been logged out successfully.');
redirect(APP_URL . '/login.php');
