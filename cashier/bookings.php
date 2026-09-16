<?php
require_once __DIR__ . '/../includes/header.php';
$status = $_GET['status'] ?? '';
$qs = $status ? "?status=" . urlencode($status) : '';
redirect(APP_URL . '/staff/bookings.php' . $qs);
