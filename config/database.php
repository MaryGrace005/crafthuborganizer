<?php
// ============================================================
//  Database Configuration — CraftHub Organizer
// ============================================================

// ── App Constants ──
if (!defined('APP_NAME')) define('APP_NAME', 'CraftHub Organizer');
if (!defined('APP_URL'))  define('APP_URL',  'http://localhost/crafthuborganizer');  // Adjust if deployed elsewhere

// ── Database Settings ──
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'crafthub');
define('DB_USER', 'root');
define('DB_PASS', '');

// PDO singleton
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Connection attempts to support different WampServer/MySQL configurations
        $attempts = [
            ['host' => DB_HOST,     'port' => 3306, 'user' => DB_USER, 'pass' => DB_PASS],
            ['host' => 'localhost', 'port' => 3306, 'user' => DB_USER, 'pass' => DB_PASS],
            ['host' => '127.0.0.1', 'port' => 3307, 'user' => DB_USER, 'pass' => DB_PASS], // WAMP MariaDB default port
            ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root',  'pass' => 'root'],
            ['host' => 'localhost', 'port' => 3306, 'user' => 'root',  'pass' => 'root'],
        ];

        $lastException = null;
        foreach ($attempts as $cred) {
            try {
                $dsn = "mysql:host={$cred['host']};port={$cred['port']};dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, $cred['user'], $cred['pass'], $options);
                break; // Connection successful!
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        if ($pdo === null) {
            die('<div style="font-family:sans-serif;padding:24px;background:#1a0a0a;color:#e94560;border-radius:8px;margin:20px;">
                 <h3 style="margin-top:0;">Database Connection Failed</h3>
                 <p>' . htmlspecialchars($lastException ? $lastException->getMessage() : 'Unable to connect to MySQL.') . '</p>
                 <small style="color:#aaa;">Please ensure WAMP MySQL/MariaDB service is running and database "crafthub" exists.</small>
                 </div>');
        }
    }
    return $pdo;
}