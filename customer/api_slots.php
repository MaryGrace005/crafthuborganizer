<?php
// ============================================================
//  AJAX: Real-Time Package Slot Availability
//  GET  ?package_id=  (optional, returns single package)
//  GET  (no params)   (returns all active packages)
//  Returns JSON with live slot data
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

// Always sync past bookings first to restore slots
syncPastBookingsAndSlots($db);

$packageId = isset($_GET['package_id']) ? (int)$_GET['package_id'] : 0;

if ($packageId > 0) {
    // Single package
    $data = getPackageLiveSlots($packageId, $db);
    echo json_encode($data);
} else {
    // All active packages
    $stmt = $db->query("
        SELECT p.package_id AS id,
               p.package_name AS name,
               COALESCE(p.max_slots, 5) AS max_slots,
               (SELECT COUNT(*) FROM bookings b
                WHERE b.package_id = p.package_id
                AND b.status IN ('Pending','Confirmed','Paid')
                AND b.event_date >= CURDATE()) AS active_bookings,
               GREATEST(0, COALESCE(p.max_slots, 5) - (SELECT COUNT(*) FROM bookings b
                WHERE b.package_id = p.package_id
                AND b.status IN ('Pending','Confirmed','Paid')
                AND b.event_date >= CURDATE())) AS available_slots
        FROM packages p
        WHERE p.status = 'active'
        ORDER BY p.base_price ASC
    ");
    $packages = $stmt->fetchAll();

    $result = [];
    foreach ($packages as $pkg) {
        $maxSlots  = (int)$pkg['max_slots'];
        $available = (int)$pkg['available_slots'];
        $active    = (int)$pkg['active_bookings'];
        $result[] = [
            'id'              => (int)$pkg['id'],
            'name'            => $pkg['name'],
            'max_slots'       => $maxSlots,
            'active_bookings' => $active,
            'available_slots' => $available,
            'is_full'         => $available <= 0,
            'pct_booked'      => $maxSlots > 0 ? min(100, round($active / $maxSlots * 100)) : 100
        ];
    }
    echo json_encode(['packages' => $result, 'synced_at' => date('Y-m-d H:i:s')]);
}
exit;
