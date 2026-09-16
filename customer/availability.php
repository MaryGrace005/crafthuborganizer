<?php
// ============================================================
//  Availability Calendar -> Redirects to the Unified Booking Calendar
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$qs = [];
if (!empty($_GET['venue_id'])) $qs[] = 'venue_id=' . (int)$_GET['venue_id'];
if (!empty($_GET['date']))     $qs[] = 'date=' . urlencode($_GET['date']);
$query = !empty($qs) ? '?' . implode('&', $qs) : '';

redirect(APP_URL . '/customer/booking.php' . $query);
exit;
