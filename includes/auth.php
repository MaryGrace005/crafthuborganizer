<?php
// ============================================================
//  Authentication Helpers - CraftHub Organizer
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------
//  Check if user is logged in
// -----------------------------------------------
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// -----------------------------------------------
//  Require Login — redirect to login if not authenticated
// -----------------------------------------------
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('warning', 'Please log in to access that page.');
        redirect(APP_URL . '/login.php');
    }
}

// -----------------------------------------------
//  Require a specific role (or array of roles)
// -----------------------------------------------
function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        setFlash('error', 'You do not have permission to access that page.');
        redirectByRole($_SESSION['user_role']);
    }
}

// -----------------------------------------------
//  Get currently logged-in user data from DB
// -----------------------------------------------
function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db   = getDB();
    $stmt = $db->prepare("SELECT *, user_id AS id, contact_no AS phone FROM users WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// -----------------------------------------------
//  Redirect user to their role dashboard
// -----------------------------------------------
function redirectByRole(string $role): void {
    $routes = [
        'admin'   => APP_URL . '/admin/dashboard.php',
        'cashier' => APP_URL . '/cashier/dashboard.php',
        'customer'=> APP_URL . '/customer/dashboard.php',
    ];
    redirect($routes[$role] ?? APP_URL . '/login.php');
}

// -----------------------------------------------
//  Login a user (call after credentials verified)
// -----------------------------------------------
function loginUser(array $user): void {
    $_SESSION['user_id']    = $user['user_id'] ?? $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    session_regenerate_id(true);
}

// -----------------------------------------------
//  Logout
// -----------------------------------------------
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// -----------------------------------------------
//  CSRF Token
// -----------------------------------------------
function generateCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
