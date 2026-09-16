<?php
// ============================================================
//  AJAX: Get All Booked Dates for a Venue
//  GET  ?venue_id=&from=YYYY-MM-DD&to=YYYY-MM-DD
//  Returns JSON: { booked_dates: ["YYYY-MM-DD", ...] }
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);

header('Content-Type: application/json; charset=utf-8');

$venueId  = (int)($_GET['venue_id'] ?? 0);
$fromDate = trim($_GET['from']      ?? date('Y-m-d'));
$toDate   = trim($_GET['to']        ?? date('Y-m-d', strtotime('+12 months')));

if (!$venueId) {
    echo json_encode(['booked_dates' => []]);
    exit;
}

// Sanitize date formats
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d', strtotime('+12 months'));

$bookedDates = getVenueBookedDates($venueId, $fromDate, $toDate);

echo json_encode(['booked_dates' => $bookedDates]);
exit;
