<?php
// ============================================================
//  Database Configuration — CraftHub Organizer
// ============================================================

// ── App Constants ──
define('APP_NAME', 'CraftHub Organizer');
define('APP_URL',  'http://localhost/crafthuborganizer');  // Adjust if deployed elsewhere

// ── Database ──
$host   = 'localhost';
$dbname = 'crafthub';
$username = 'root';
$password = '';

// PDO singleton
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $host, $dbname, $username, $password;
        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            die('<div style="font-family:monospace;padding:20px;background:#1a0a0a;color:#e94560;">
                 <strong>Database connection failed:</strong><br>' . htmlspecialchars($e->getMessage()) . '
                 </div>');
        }
    }
    return $pdo;
}