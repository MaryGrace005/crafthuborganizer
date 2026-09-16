<?php
require_once __DIR__ . '/../includes/header.php';
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$qs = http_build_query(array_filter(['search' => $search, 'status' => $status]));
redirect(APP_URL . '/staff/bills.php' . ($qs ? "?{$qs}" : ''));
