<?php
// ============================================================
//  AJAX: Check Venue+Date Availability
//  GET  ?venue_id=&event_date=YYYY-MM-DD[&exclude_booking_id=]
//  Returns JSON: { available: bool, message: string }
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);

header('Content-Type: application/json; charset=utf-8');

$venueId         = (int)($_GET['venue_id']          ?? 0);
$eventDate       = trim($_GET['event_date']          ?? '');
$excludeId       = isset($_GET['exclude_booking_id']) ? (int)$_GET['exclude_booking_id'] : null;

// Validate inputs
if (!$venueId || !$eventDate) {
    echo json_encode(['available' => true, 'message' => 'No venue selected.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    echo json_encode(['available' => false, 'message' => 'Invalid date format.']);
    exit;
}

// Must be at least tomorrow
if ($eventDate <= date('Y-m-d')) {
    echo json_encode(['available' => false, 'message' => 'Event date must be in the future.']);
    exit;
}

// Check venue exists
$db    = getDB();
$vStmt = $db->prepare("SELECT venue_name FROM venues WHERE venue_id = ? AND availability_status = 'available'");
$vStmt->execute([$venueId]);
$venue = $vStmt->fetch();

if (!$venue) {
    echo json_encode(['available' => false, 'message' => 'Selected venue is not available.']);
    exit;
}

// Core availability check
$available = isVenueDateAvailable($venueId, $eventDate, $excludeId);

if ($available) {
    echo json_encode([
        'available' => true,
        'message'   => htmlspecialchars($venue['venue_name']) . ' is available on this date!'
    ]);
} else {
    // Find out who has it (without exposing customer info)
    $bStmt = $db->prepare("
        SELECT event_date FROM bookings
        WHERE venue_id = ? AND event_date = ? AND status NOT IN ('Cancelled')
        LIMIT 1
    ");
    $bStmt->execute([$venueId, $eventDate]);

    echo json_encode([
        'available' => false,
        'message'   => htmlspecialchars($venue['venue_name']) . ' is already booked on ' .
                       date('F j, Y', strtotime($eventDate)) . '. Please choose a different date or venue.'
    ]);
}
exit;
