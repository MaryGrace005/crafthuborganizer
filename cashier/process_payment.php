<?php
require_once __DIR__ . '/../includes/header.php';
$id = (int)($_GET['id'] ?? 0);
redirect(APP_URL . '/staff/process_payment.php' . ($id ? "?id={$id}" : ''));
