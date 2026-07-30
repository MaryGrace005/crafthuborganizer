<?php
/**
 * CraftHub Organizer — Database & Asset Migration Runner
 * Open: http://localhost/crafthuborganizer/migrate.php
 */
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    die('<h2 style="color:red;">Access denied.</h2>');
}

require_once __DIR__ . '/config/database.php';
$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

$results = [];

// ── 1. Copy sample package images ──────────────────────────────────────────
$targetDir = __DIR__ . '/assets/images/packages';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$artifactsDir = 'C:\Users\Mary Grace Cadenas\.gemini\antigravity-ide\brain\19ced39b-af6e-44f8-89df-036a7c7170a4';
$mapping = [
    'wedding_package'    => 'wedding.png',
    'birthday_package'   => 'birthday.png',
    'debut_package'      => 'debut.png',
    'christening_package'=> 'christening.png',
];

$copied = 0;
$files = glob($artifactsDir . '/*.png');
foreach ($files as $file) {
    foreach ($mapping as $key => $targetName) {
        if (strpos(basename($file), $key) !== false) {
            copy($file, $targetDir . '/' . $targetName);
            $copied++;
        }
    }
}
$results[] = ['label' => 'Sample package images', 'ok' => true, 'msg' => "{$copied} image(s) copied to assets/images/packages/"];

// ── 2. ip_address on users ─────────────────────────────────────────────────
if (columnExists($db, 'users', 'ip_address')) {
    $results[] = ['label' => 'ip_address on users', 'ok' => true, 'msg' => 'Already exists.'];
} else {
    try {
        $db->exec("ALTER TABLE users ADD COLUMN ip_address VARCHAR(45) UNIQUE NULL");
        $results[] = ['label' => 'ip_address on users', 'ok' => true, 'msg' => 'Column added.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'ip_address on users', 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// ── 3. max_slots on packages ───────────────────────────────────────────────
if (columnExists($db, 'packages', 'max_slots')) {
    $results[] = ['label' => 'max_slots on packages', 'ok' => true, 'msg' => 'Already exists.'];
} else {
    try {
        $db->exec("ALTER TABLE packages ADD COLUMN max_slots INT NOT NULL DEFAULT 5");
        $results[] = ['label' => 'max_slots on packages', 'ok' => true, 'msg' => 'Column added.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'max_slots on packages', 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// ── 4. image_url on packages ───────────────────────────────────────────────
if (columnExists($db, 'packages', 'image_url')) {
    $results[] = ['label' => 'image_url on packages', 'ok' => true, 'msg' => 'Already exists.'];
} else {
    try {
        $db->exec("ALTER TABLE packages ADD COLUMN image_url VARCHAR(500) NULL");
        $results[] = ['label' => 'image_url on packages', 'ok' => true, 'msg' => 'Column added.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'image_url on packages', 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// Update default images for packages based on event type if null
try {
    $db->exec("UPDATE packages SET image_url = 'assets/images/packages/wedding.png' WHERE (image_url IS NULL OR image_url = '') AND event_type = 'Wedding'");
    $db->exec("UPDATE packages SET image_url = 'assets/images/packages/birthday.png' WHERE (image_url IS NULL OR image_url = '') AND event_type = 'Birthday'");
    $db->exec("UPDATE packages SET image_url = 'assets/images/packages/debut.png' WHERE (image_url IS NULL OR image_url = '') AND event_type = 'Debut'");
    $db->exec("UPDATE packages SET image_url = 'assets/images/packages/christening.png' WHERE (image_url IS NULL OR image_url = '') AND event_type = 'Christening'");
    // Fallback for any other event_type
    $db->exec("UPDATE packages SET image_url = 'assets/images/packages/wedding.png' WHERE image_url IS NULL OR image_url = ''");
    $results[] = ['label' => 'Package sample images mapping', 'ok' => true, 'msg' => 'Updated packages with sample image URLs.'];
} catch (PDOException $e) {
    $results[] = ['label' => 'Package sample images mapping', 'ok' => false, 'msg' => $e->getMessage()];
}

// ── 5. booking_images table ────────────────────────────────────────────────
if (tableExists($db, 'booking_images')) {
    $results[] = ['label' => 'booking_images table', 'ok' => true, 'msg' => 'Already exists.'];
} else {
    try {
        $db->exec("
            CREATE TABLE booking_images (
                image_id    INT AUTO_INCREMENT PRIMARY KEY,
                booking_id  INT          NOT NULL,
                uploaded_by INT          NOT NULL,
                file_name   VARCHAR(255) NOT NULL,
                file_path   VARCHAR(500) NOT NULL,
                mime_type   VARCHAR(100) NOT NULL DEFAULT 'image/jpeg',
                file_size   INT          NOT NULL DEFAULT 0,
                caption     VARCHAR(255) NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_booking_id (booking_id),
                INDEX idx_uploaded_by (uploaded_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $results[] = ['label' => 'booking_images table', 'ok' => true, 'msg' => 'Table created successfully.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'booking_images table', 'ok' => false, 'msg' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DB & Asset Migration</title>
<style>
  body{font-family:Arial,sans-serif;max-width:700px;margin:40px auto;padding:0 20px;}
  h2{border-bottom:2px solid #333;padding-bottom:8px;}
  .ok  {background:#d4edda;border:1px solid #c3e6cb;padding:10px 14px;border-radius:6px;margin:8px 0;color:#155724;}
  .fail{background:#f8d7da;border:1px solid #f5c6cb;padding:10px 14px;border-radius:6px;margin:8px 0;color:#721c24;}
  .warn{margin-top:20px;background:#fff3cd;border:1px solid #ffc107;padding:12px;border-radius:6px;font-weight:bold;}
</style>
</head>
<body>
<h2>🗄️ CraftHub Migration & Asset Setup</h2>

<?php foreach ($results as $r): ?>
  <div class="<?= $r['ok'] ? 'ok' : 'fail' ?>">
    <?= $r['ok'] ? '✅' : '❌' ?> <strong><?= htmlspecialchars($r['label']) ?></strong><br>
    <small><?= htmlspecialchars($r['msg']) ?></small>
  </div>
<?php endforeach; ?>

<div class="warn">
  ⚠️ All setup tasks completed! Refresh <a href="http://localhost/crafthuborganizer/customer/packages.php">Customer Packages</a> to see your package cards with high quality photos!
</div>
</body>
</html>
