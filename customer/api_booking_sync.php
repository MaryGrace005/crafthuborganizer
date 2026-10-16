<?php
// ============================================================
//  AJAX: Real-Time Booking Sync & Status
//  GET  ?action=sync         -> triggers sync, returns count of updated rows
//  GET  ?action=status       -> returns booking counts by status
//  GET  ?action=my_bookings  -> returns customer's booking statuses (customer only)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Allow access for all authenticated roles
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$action = sanitize($_GET['action'] ?? 'sync');

// Always sync first
syncPastBookingsAndSlots($db);

switch ($action) {
    case 'sync':
        echo json_encode([
            'synced'    => true,
            'synced_at' => date('Y-m-d H:i:s'),
            'message'   => 'Past bookings synchronized. Slots restored.'
        ]);
        break;

    case 'status':
        $counts = [];
        foreach (['Pending', 'Confirmed', 'Paid', 'Completed', 'Cancelled'] as $status) {
            $s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE status = ?");
            $s->execute([$status]);
            $counts[strtolower($status)] = (int)$s->fetchColumn();
        }
        echo json_encode([
            'counts'    => $counts,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
        break;

    case 'my_bookings':
        $userId = $_SESSION['user_id'] ?? 0;
        $stmt = $db->prepare("
            SELECT b.booking_id AS id, b.status, b.event_date,
                   p.package_name AS package_name
            FROM bookings b
            JOIN packages p ON b.package_id = p.package_id
            WHERE b.customer_id = ?
            ORDER BY b.created_at DESC
        ");
        $stmt->execute([$userId]);
        $bookings = $stmt->fetchAll();
        echo json_encode([
            'bookings'  => $bookings,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
exit;
