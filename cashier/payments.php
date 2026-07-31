<?php
require_once __DIR__ . '/../includes/header.php';
$filter = $_GET['filter'] ?? '';
redirect(APP_URL . '/staff/payments.php' . ($filter ? "?filter={$filter}" : ''));
