<?php
require_once __DIR__ . '/config/database.php';
$db = getDB();

try {
    $db->exec("ALTER TABLE users
        MODIFY COLUMN name  VARCHAR(65) NOT NULL COMMENT 'Auto-built display name; max 65 chars',
        MODIFY COLUMN email VARCHAR(50) NOT NULL
    ");
    echo "✅ Applied successfully.\n\n";

    $cols = $db->query("SHOW COLUMNS FROM users WHERE Field IN ('name','email')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']}: {$c['Type']}\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
