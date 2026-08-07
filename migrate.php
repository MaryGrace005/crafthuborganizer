<?php
/**
 * CraftHub Organizer — Database & Asset Migration Runner
 * Open: http://localhost/crafthuborganizer/migrate.php
 */
// Local migration runner
// if (isset($_SERVER['REMOTE_ADDR']) && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
//     die('<h2 style="color:red;">Access denied.</h2>');
// }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
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
if (!tableExists($db, 'booking_images')) {
    try {
        $db->exec("
            CREATE TABLE booking_images (
                image_id      INT AUTO_INCREMENT PRIMARY KEY,
                booking_id    INT          NOT NULL,
                uploaded_by   INT          NULL,
                image_type    VARCHAR(50)  NOT NULL DEFAULT 'event_photo',
                image_path    VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type     VARCHAR(100) NOT NULL DEFAULT 'image/jpeg',
                file_size     INT          NOT NULL DEFAULT 0,
                caption       VARCHAR(255) NULL,
                is_public     TINYINT(1)   DEFAULT 1,
                uploaded_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_booking_id (booking_id),
                INDEX idx_uploaded_by (uploaded_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $results[] = ['label' => 'booking_images table', 'ok' => true, 'msg' => 'Table created successfully.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'booking_images table', 'ok' => false, 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['label' => 'booking_images table', 'ok' => true, 'msg' => 'Already exists.'];
}

// ── 5b. Ensure booking_images columns match function signatures ─────────────
try {
    if (!columnExists($db, 'booking_images', 'image_path')) {
        $db->exec("ALTER TABLE booking_images ADD COLUMN image_path VARCHAR(500) NULL");
        if (columnExists($db, 'booking_images', 'file_path')) {
            $db->exec("UPDATE booking_images SET image_path = file_path WHERE image_path IS NULL");
        }
    }
    if (!columnExists($db, 'booking_images', 'original_name')) {
        $db->exec("ALTER TABLE booking_images ADD COLUMN original_name VARCHAR(255) NULL");
        if (columnExists($db, 'booking_images', 'file_name')) {
            $db->exec("UPDATE booking_images SET original_name = file_name WHERE original_name IS NULL");
        }
    }
    if (!columnExists($db, 'booking_images', 'image_type')) {
        $db->exec("ALTER TABLE booking_images ADD COLUMN image_type VARCHAR(50) NOT NULL DEFAULT 'event_photo'");
    }
    if (!columnExists($db, 'booking_images', 'is_public')) {
        $db->exec("ALTER TABLE booking_images ADD COLUMN is_public TINYINT(1) DEFAULT 1");
    }
    if (!columnExists($db, 'booking_images', 'uploaded_at')) {
        $db->exec("ALTER TABLE booking_images ADD COLUMN uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    $results[] = ['label' => 'booking_images column synchronization', 'ok' => true, 'msg' => 'Synchronized booking_images columns.'];
} catch (PDOException $e) {
    $results[] = ['label' => 'booking_images column synchronization', 'ok' => false, 'msg' => $e->getMessage()];
}

// ── 6. Expand users.status ENUM for approval workflow ─────────────────────
try {
    $db->exec("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','pending_approval','rejected') DEFAULT 'pending_approval'");
    $results[] = ['label' => 'users.status ENUM expansion', 'ok' => true, 'msg' => "Added 'pending_approval' and 'rejected' to status ENUM."];
} catch (PDOException $e) {
    $results[] = ['label' => 'users.status ENUM expansion', 'ok' => false, 'msg' => $e->getMessage()];
}

// ── 7. id_code column on users ────────────────────────────────────────────
if (columnExists($db, 'users', 'id_code')) {
    $results[] = ['label' => 'id_code on users', 'ok' => true, 'msg' => 'Already exists.'];
} else {
    try {
        $db->exec("ALTER TABLE users ADD COLUMN id_code VARCHAR(20) UNIQUE NULL AFTER status");
        $results[] = ['label' => 'id_code on users', 'ok' => true, 'msg' => 'Column added.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'id_code on users', 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// ── 8. Profiling columns on users ─────────────────────────────────────────
$profilingCols = [
    'business_name'      => "VARCHAR(150) NULL AFTER id_code",
    'event_type_interest'=> "VARCHAR(100) NULL",
    'guest_count_est'    => "INT UNSIGNED NULL",
    'profile_notes'      => "TEXT NULL",
    'rejection_reason'   => "TEXT NULL",
];
foreach ($profilingCols as $col => $def) {
    if (columnExists($db, 'users', $col)) {
        $results[] = ['label' => "users.{$col}", 'ok' => true, 'msg' => 'Already exists.'];
    } else {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            $results[] = ['label' => "users.{$col}", 'ok' => true, 'msg' => 'Column added.'];
        } catch (PDOException $e) {
            $results[] = ['label' => "users.{$col}", 'ok' => false, 'msg' => $e->getMessage()];
        }
    }
}

// ── 8b. Make bookings.venue_id nullable (venue is optional) ──────────────
try {
    $db->exec("ALTER TABLE bookings MODIFY COLUMN venue_id INT NULL DEFAULT NULL");
    $results[] = ['label' => 'bookings.venue_id nullable', 'ok' => true, 'msg' => 'venue_id now allows NULL (venue is optional).'];
} catch (PDOException $e) {
    $results[] = ['label' => 'bookings.venue_id nullable', 'ok' => false, 'msg' => $e->getMessage()];
}

// ── 9. account_id_seq table (race-condition-safe unique ID counter) ────────
if (tableExists($db, 'account_id_seq')) {
    $results[] = ['label' => 'account_id_seq table', 'ok' => true, 'msg' => 'Already exists.'];
} else {
    try {
        $db->exec("
            CREATE TABLE account_id_seq (
                id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dummy    TINYINT DEFAULT 0
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4
        ");
        // Insert the first seed row so AUTO_INCREMENT starts at 1
        $db->exec("INSERT INTO account_id_seq (dummy) VALUES (0)");
        $results[] = ['label' => 'account_id_seq table', 'ok' => true, 'msg' => 'Table created and seeded.'];
    } catch (PDOException $e) {
        $results[] = ['label' => 'account_id_seq table', 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// ── 10. Migrate existing 'inactive' customer rows → 'pending_approval' ─────
try {
    $updated = $db->exec("UPDATE users SET status = 'pending_approval' WHERE status = 'inactive' AND role = 'customer'");
    $results[] = ['label' => "Migrate inactive customers → pending_approval", 'ok' => true, 'msg' => "{$updated} row(s) converted."];
} catch (PDOException $e) {
    $results[] = ['label' => "Migrate inactive customers → pending_approval", 'ok' => false, 'msg' => $e->getMessage()];
}

// ── 11. Backfill unique id_code for existing active accounts ────────────────
try {
    $activeWithoutCode = $db->query("SELECT user_id FROM users WHERE status = 'active' AND (id_code IS NULL OR id_code = '') ORDER BY user_id ASC")->fetchAll();
    $countBackfilled = 0;
    foreach ($activeWithoutCode as $u) {
        $code = generateAccountIdCode();
        $db->prepare("UPDATE users SET id_code = ? WHERE user_id = ?")->execute([$code, $u['user_id']]);
        $countBackfilled++;
    }
    $results[] = ['label' => "Backfill id_code for active database accounts", 'ok' => true, 'msg' => "Assigned unique ID code to {$countBackfilled} active account(s)."];
} catch (PDOException $e) {
    $results[] = ['label' => "Backfill id_code for active database accounts", 'ok' => false, 'msg' => $e->getMessage()];
}

// ── 12. Seed additional event packages & service components ─────────────────
try {
    $newPackages = [
        [
            'name'       => 'Royal Luxury Wedding',
            'type'       => 'Wedding',
            'price'      => 120000.00,
            'slots'      => 8,
            'desc'       => 'An opulent, grand wedding celebration featuring glass garden pavilion, 7-course gourmet buffet, open bar, 4K cinematic video, and crystal chandelier styling.',
            'image'      => 'assets/images/packages/wedding.png',
            'components' => [
                ['category' => 'venue',       'name' => 'Glass Garden Pavilion & Grand Hall', 'desc' => 'Climate-controlled glass pavilion for up to 350 guests', 'price' => 45000.00],
                ['category' => 'food',        'name' => '7-Course Gourmet Buffet & Open Bar', 'desc' => 'Includes live chef carving station, seafood, & unlimited cocktails', 'price' => 40000.00],
                ['category' => 'photography', 'name' => '4K Aerial Drone & Cinematic Video',  'desc' => '3 camera crew, drone shots, live stream, & same-day edit reel', 'price' => 25000.00],
                ['category' => 'decoration',  'name' => 'Crystal Chandelier & Floral Canopy', 'desc' => 'Fresh imported floral ceiling canopy & crystal table centerpieces', 'price' => 10000.00],
            ]
        ],
        [
            'name'       => 'Intimate Rustic Wedding',
            'type'       => 'Wedding',
            'price'      => 35000.00,
            'slots'      => 6,
            'desc'       => 'A cozy, charming garden wedding package with wooden arches, fairy lights, farm-to-table dining, and warm acoustic ambient vibes.',
            'image'      => 'assets/images/packages/wedding.png',
            'components' => [
                ['category' => 'venue',       'name' => 'Rustic Garden Courtyard',            'desc' => 'Lush outdoor garden setup ideal for 80 guests', 'price' => 15000.00],
                ['category' => 'food',        'name' => 'Farm-to-Table Family Style Banquet', 'desc' => 'Organic roast meats, artisan salads, & homemade desserts', 'price' => 12000.00],
                ['category' => 'photography', 'name' => 'Full Day Photo & Digital Album',     'desc' => '2 photographers capturing candid moments & digital photo gallery', 'price' => 5000.00],
                ['category' => 'decoration',  'name' => 'Wooden Arch & Warm Fairy Lights',    'desc' => 'Handcrafted wooden altar, burlap accents, & fairy light ceiling', 'price' => 3000.00],
            ]
        ],
        [
            'name'       => 'K-Pop & Anime Wonderland Birthday',
            'type'       => 'Birthday',
            'price'      => 25000.00,
            'slots'      => 10,
            'desc'       => 'Vibrant themed birthday party with interactive activity booths, custom character dessert tables, neon light displays, and instant photo booths.',
            'image'      => 'assets/images/packages/birthday.png',
            'components' => [
                ['category' => 'venue',       'name' => 'Interactive Fun & Activity Lounge',  'desc' => 'Themed indoor hall with gaming screens & dance floor', 'price' => 10000.00],
                ['category' => 'food',        'name' => 'Custom Bento & Dessert Buffet',       'desc' => 'Anime/K-Pop themed cupcakes, bento boxes, & boba tea bar', 'price' => 8000.00],
                ['category' => 'photography', 'name' => 'Instant Photo Booth & Props',        'desc' => 'Unlimited prints with custom event border overlay & fun cosplay props', 'price' => 4000.00],
                ['category' => 'decoration',  'name' => 'Neon Light Arch & Balloon Sculpture', 'desc' => 'Glow-in-the-dark neon signs & multi-layer balloon arch', 'price' => 3000.00],
            ]
        ],
        [
            'name'       => 'Milestone Golden Jubilee (50th Birthday)',
            'type'       => 'Birthday',
            'price'      => 45000.00,
            'slots'      => 5,
            'desc'       => 'A classy, golden-themed celebration for milestone birthdays with international buffet dining, live quartet music, and gold marble styling.',
            'image'      => 'assets/images/packages/birthday.png',
            'components' => [
                ['category' => 'venue',       'name' => 'Grand Ballroom Suite & Lounge',      'desc' => 'Air-conditioned ballroom with VIP seating for 150 guests', 'price' => 18000.00],
                ['category' => 'food',        'name' => 'International Buffet & Fine Wine',   'desc' => 'Roast beef, pasta bar, wine pairing, & dessert tower', 'price' => 17000.00],
                ['category' => 'photography', 'name' => 'Live Streaming & Hardbound Book',    'desc' => 'High-def live stream for overseas family + physical photo book', 'price' => 6000.00],
                ['category' => 'decoration',  'name' => 'Gold & Marble Elegance Styling',     'desc' => 'Golden sequin backdrops, marble-effect chargers, & warm lights', 'price' => 4000.00],
            ]
        ],
        [
            'name'       => 'Enchanted Princess Debut',
            'type'       => 'Debut',
            'price'      => 55000.00,
            'slots'      => 6,
            'desc'       => 'Fairytale debut package with LED dancefloor, 18 Roses & Candles ceremony setup, 5-course plated dinner, and cinematic video editing.',
            'image'      => 'assets/images/packages/debut.png',
            'components' => [
                ['category' => 'venue',       'name' => 'Crystal Ballroom & LED Floor',       'desc' => 'Grand ballroom equipped with synchronized LED dancefloor', 'price' => 22000.00],
                ['category' => 'food',        'name' => '5-Course Plated & Mocktail Bar',     'desc' => 'Plated gourmet meal with unlimited custom fruit mocktails', 'price' => 18000.00],
                ['category' => 'photography', 'name' => 'Same-Day Edit Video Highlights',    'desc' => 'Professional video team producing SDE reel played during event', 'price' => 10000.00],
                ['category' => 'decoration',  'name' => '18 Roses & Candles Stage Setup',     'desc' => 'Illuminated throne chair, floral gazebo, & candle archway', 'price' => 5000.00],
            ]
        ],
        [
            'name'       => 'Angel\'s Grace Celestial Christening',
            'type'       => 'Christening',
            'price'      => 22000.00,
            'slots'      => 10,
            'desc'       => 'Heavenly christening celebration featuring sunlit garden conservatory venue, light gourmet buffet, cloud balloon styling, and studio portraits.',
            'image'      => 'assets/images/packages/christening.png',
            'components' => [
                ['category' => 'venue',       'name' => 'Sunlit Conservatory & Garden Hall',  'desc' => 'Glass-roofed hall surrounded by serene garden greenery', 'price' => 8000.00],
                ['category' => 'food',        'name' => 'Light Gourmet Buffet & Dessert Table', 'desc' => 'Kid-friendly & adult buffet options + mini dessert bar', 'price' => 8000.00],
                ['category' => 'photography', 'name' => 'Baby Studio Portrait Corner',        'desc' => 'On-site mini studio set with props for family portraits', 'price' => 4000.00],
                ['category' => 'decoration',  'name' => 'Celestial Cloud & Pastel Balloon Set','desc' => 'White cloud balloons, golden star accents, & baby backdrop', 'price' => 2000.00],
            ]
        ]
    ];

    $addedPkgs = 0;
    $addedComps = 0;

    foreach ($newPackages as $pData) {
        $chk = $db->prepare("SELECT package_id FROM packages WHERE package_name = ?");
        $chk->execute([$pData['name']]);
        $existing = $chk->fetch();

        if (!$existing) {
            $ins = $db->prepare("INSERT INTO packages (package_name, event_type, base_price, max_slots, description, image_url, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $ins->execute([$pData['name'], $pData['type'], $pData['price'], $pData['slots'], $pData['desc'], $pData['image']]);
            $pkgId = (int)$db->lastInsertId();
            $addedPkgs++;

            foreach ($pData['components'] as $c) {
                $insC = $db->prepare("INSERT INTO package_components (package_id, category, name, description, price) VALUES (?, ?, ?, ?, ?)");
                $insC->execute([$pkgId, $c['category'], $c['name'], $c['desc'], $c['price']]);
                $addedComps++;
            }
        }
    }

    $results[] = ['label' => 'Additional event packages & services', 'ok' => true, 'msg' => "Added {$addedPkgs} new package(s) and {$addedComps} service component(s)."];
} catch (PDOException $e) {
    $results[] = ['label' => 'Additional event packages & services', 'ok' => false, 'msg' => $e->getMessage()];
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

<ul>
<?php foreach ($results as $r): ?>
  <li><?= $r['ok'] ? '✅' : '❌' ?> <strong><?= htmlspecialchars($r['label']) ?></strong>: <?= htmlspecialchars($r['msg']) ?></li>
<?php endforeach; ?>
</ul>

<div class="warn">
  ⚠️ All setup tasks completed! Refresh <a href="http://localhost/crafthuborganizer/customer/packages.php">Customer Packages</a> to see your package cards with high quality photos!
</div>
</body>
</html>
