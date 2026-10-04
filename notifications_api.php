<?php
// ============================================================
//  Notifications API — CraftHub Organizer
//  Handles fetching unread notifications and marking as read
// ============================================================
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit();
}

$user   = getCurrentUser();
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list') {
    $notifications = getUserNotifications($userId, 15);
    $unreadCount   = getUserUnreadNotificationsCount($userId);
    echo json_encode([
        'success'       => true,
        'unread_count'  => $unreadCount,
        'notifications' => $notifications
    ]);
    exit();
}

if ($action === 'mark_read') {
    markNotificationsRead($userId);
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit();
