<?php
// Root index — redirect based on login status
require_once __DIR__ . '/includes/header.php';

if (isLoggedIn()) {
    redirectByRole($_SESSION['user_role']);
} else {
    redirect(APP_URL . '/landing.php');
}
