<?php
/**
 * CraftHub Organizer — Database Migration: Name Segregation
 *
 * Segregates full names into First Name, Middle Name, Last Name, and Surname.
 *
 * Web URL: http://localhost/crafthuborganizer/migrate_segregate_names.php
 * CLI:     php migrate_segregate_names.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$isCli = (php_sapi_name() === 'cli');

function columnExistsLocal(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

// Ensure columns exist in `users`
$colsToAdd = [
    'first_name'  => "VARCHAR(50) NULL AFTER name",
    'middle_name' => "VARCHAR(50) NULL AFTER first_name",
    'last_name'   => "VARCHAR(50) NULL AFTER middle_name",
    'surname'     => "VARCHAR(50) NULL AFTER last_name",
];

$schemaUpdates = [];
foreach ($colsToAdd as $col => $def) {
    if (!columnExistsLocal($db, 'users', $col)) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            $schemaUpdates[] = "Added column `users`.`{$col}`";
        } catch (PDOException $e) {
            $schemaUpdates[] = "Column `{$col}` check/add error: " . $e->getMessage();
        }
    }
}

/**
 * Intelligent full name splitter
 */
function parseFullName(string $fullName): array {
    $parts = preg_split('/\s+/', trim($fullName));
    $parts = array_values(array_filter($parts, 'strlen'));
    $count = count($parts);

    if ($count === 0) {
        return ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'surname' => ''];
    }
    if ($count === 1) {
        return [
            'first_name'  => $parts[0],
            'middle_name' => '',
            'last_name'   => $parts[0],
            'surname'     => $parts[0]
        ];
    }
    if ($count === 2) {
        return [
            'first_name'  => $parts[0],
            'middle_name' => '',
            'last_name'   => $parts[1],
            'surname'     => $parts[1]
        ];
    }
    if ($count === 3) {
        return [
            'first_name'  => $parts[0],
            'middle_name' => $parts[1],
            'last_name'   => $parts[2],
            'surname'     => $parts[2]
        ];
    }
    // 4 or more words (e.g., "Mary Grace Ann Cadenas")
    $lastName = array_pop($parts);
    $middleName = array_pop($parts);
    $firstName = implode(' ', $parts);
    return [
        'first_name'  => $firstName,
        'middle_name' => $middleName,
        'last_name'   => $lastName,
        'surname'     => $lastName
    ];
}

$message = '';
$messageType = '';
$updatedCount = 0;

if ($isCli || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_segregation']))) {
    try {
        $forceResplit = !empty($_POST['force_resplit']) || ($isCli && in_array('--force', $argv ?? []));
        $users = $db->query("SELECT user_id, name, first_name, middle_name, last_name, surname FROM users")->fetchAll();

        $updateStmt = $db->prepare("
            UPDATE users 
            SET first_name  = ?, 
                middle_name = ?, 
                last_name   = ?, 
                surname     = ? 
            WHERE user_id   = ?
        ");

        foreach ($users as $u) {
            $hasFirst = !empty(trim($u['first_name'] ?? ''));
            $hasLast  = !empty(trim($u['last_name'] ?? '')) || !empty(trim($u['surname'] ?? ''));

            if ($forceResplit || !$hasFirst || !$hasLast) {
                $rawName = trim($u['name'] ?? '');
                if ($rawName !== '') {
                    $parsed = parseFullName($rawName);
                    
                    // If user already had a custom middle name and not forcing resplit, keep it
                    if (!$forceResplit && !empty($u['middle_name'])) {
                        $parsed['middle_name'] = $u['middle_name'];
                    }

                    $updateStmt->execute([
                        $parsed['first_name'],
                        $parsed['middle_name'],
                        $parsed['last_name'],
                        $parsed['surname'],
                        $u['user_id']
                    ]);
                    $updatedCount++;
                }
            } else {
                // Keep last_name and surname synced if one was set and the other wasn't
                $finalLast = $u['last_name'] ?: $u['surname'];
                if ($u['last_name'] !== $finalLast || $u['surname'] !== $finalLast) {
                    $db->prepare("UPDATE users SET last_name = ?, surname = ? WHERE user_id = ?")
                       ->execute([$finalLast, $finalLast, $u['user_id']]);
                    $updatedCount++;
                }
            }
        }

        logAudit(0, 'NAME_SEGREGATION_MIGRATION', "Segregated names for {$updatedCount} users in database", 'users');
        $message = "Migration successful! Segregated and synchronized names for <strong>{$updatedCount}</strong> user account(s).";
        $messageType = "success";
    } catch (Exception $e) {
        $message = "Error during migration: " . $e->getMessage();
        $messageType = "error";
    }

    if ($isCli) {
        echo "===========================================\n";
        echo "CraftHub Name Segregation Migration CLI\n";
        echo "===========================================\n";
        echo strip_tags($message) . "\n";
        echo "===========================================\n";
        exit($messageType === 'success' ? 0 : 1);
    }
}

// Fetch all users to display current database state
$usersList = [];
try {
    $usersList = $db->query("SELECT user_id, name, first_name, middle_name, last_name, surname, email, role FROM users ORDER BY user_id ASC")->fetchAll();
} catch (Exception $e) {
    //
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Name Segregation Migration — CraftHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-base: #0f111a;
            --bg-card: rgba(26, 29, 45, 0.88);
            --border-card: rgba(255, 255, 255, 0.08);
            --primary: #8b5cf6;
            --primary-hover: #7c3aed;
            --accent: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(at 0% 0%, rgba(139, 92, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(6, 182, 212, 0.12) 0px, transparent 50%);
            color: var(--text-main);
            min-height: 100vh;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 980px;
        }

        .card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-card);
            border-radius: 20px;
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.5);
            padding: 36px;
        }

        .header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-card);
        }

        .header-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--accent), var(--primary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: #fff;
            box-shadow: 0 8px 16px rgba(6, 182, 212, 0.3);
        }

        .header-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
        }

        .header-text p {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .schema-badge {
            display: inline-block;
            background: rgba(6, 182, 212, 0.12);
            color: #22d3ee;
            border: 1px solid rgba(6, 182, 212, 0.25);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            margin: 4px 4px 4px 0;
            font-family: monospace;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 24px;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--primary));
            color: #fff;
            box-shadow: 0 4px 14px rgba(6, 182, 212, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.45);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-card);
            color: #cbd5e1;
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 24px;
            border-radius: 12px;
            border: 1px solid var(--border-card);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        th {
            background: rgba(15, 17, 26, 0.7);
            text-align: left;
            padding: 12px 14px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-card);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            color: #e2e8f0;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .val-pill {
            display: inline-block;
            background: rgba(255, 255, 255, 0.06);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.82rem;
            font-family: monospace;
        }

        .val-empty {
            color: rgba(255, 255, 255, 0.25);
            font-style: italic;
        }

        .actions-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 20px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-muted);
            cursor: pointer;
        }

        .checkbox-label input {
            accent-color: var(--primary);
            width: 16px;
            height: 16px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <div class="header-icon">
                <i class="fa-solid fa-address-card"></i>
            </div>
            <div class="header-text">
                <h1>Database Name Segregation Migration</h1>
                <p>Splits combined full names into <code>first_name</code>, <code>middle_name</code>, <code>last_name</code>, and <code>surname</code></p>
            </div>
        </div>

        <?php if (!empty($schemaUpdates)): ?>
            <div style="margin-bottom: 20px; padding: 14px 18px; background: rgba(0,0,0,0.25); border-radius: 10px; border: 1px solid var(--border-card);">
                <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase;">
                    <i class="fa-solid fa-table-columns"></i> Schema Columns Verified:
                </div>
                <div>
                    <span class="schema-badge">users.first_name</span>
                    <span class="schema-badge">users.middle_name</span>
                    <span class="schema-badge">users.last_name</span>
                    <span class="schema-badge">users.surname</span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-xmark' ?>" style="font-size: 1.2rem;"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="run_segregation" value="1">
            <div class="actions-bar">
                <label class="checkbox-label">
                    <input type="checkbox" name="force_resplit" value="1">
                    Force re-split from full name (overwrites any existing first/last names)
                </label>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-arrows-split-up-and-left"></i> Run Name Segregation Migration
                </button>
            </div>
        </form>

        <div style="margin-top: 32px;">
            <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 12px;">
                <i class="fa-solid fa-users"></i> Users in Database (<?= count($usersList) ?>)
            </h3>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Combined (<code>name</code>)</th>
                            <th>First Name (<code>first_name</code>)</th>
                            <th>Middle Name (<code>middle_name</code>)</th>
                            <th>Last Name (<code>last_name</code>)</th>
                            <th>Surname (<code>surname</code>)</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $u): ?>
                            <tr>
                                <td>#<?= $u['user_id'] ?></td>
                                <td><strong><?= htmlspecialchars($u['name'] ?? '') ?></strong></td>
                                <td>
                                    <?php if (!empty($u['first_name'])): ?>
                                        <span class="val-pill" style="color:#6ee7b7;"><?= htmlspecialchars($u['first_name']) ?></span>
                                    <?php else: ?>
                                        <span class="val-empty">— empty —</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($u['middle_name'])): ?>
                                        <span class="val-pill" style="color:#93c5fd;"><?= htmlspecialchars($u['middle_name']) ?></span>
                                    <?php else: ?>
                                        <span class="val-empty">— empty —</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($u['last_name'])): ?>
                                        <span class="val-pill" style="color:#fde047;"><?= htmlspecialchars($u['last_name']) ?></span>
                                    <?php else: ?>
                                        <span class="val-empty">— empty —</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($u['surname'])): ?>
                                        <span class="val-pill" style="color:#fde047;"><?= htmlspecialchars($u['surname']) ?></span>
                                    <?php else: ?>
                                        <span class="val-empty">— empty —</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="font-size:0.8rem;color:var(--text-muted);text-transform:uppercase;"><?= htmlspecialchars($u['role']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex;justify-content:space-between;margin-top:28px;padding-top:20px;border-top:1px solid var(--border-card);">
            <a href="<?= APP_URL ?>/migrate.php" class="btn btn-outline">
                <i class="fa-solid fa-arrow-left"></i> Full Migration Runner
            </a>
            <a href="<?= APP_URL ?>/migrate_admin.php" class="btn btn-outline">
                <i class="fa-solid fa-user-shield"></i> Admin Migration
            </a>
            <a href="<?= APP_URL ?>/login.php" class="btn btn-outline">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Login Page
            </a>
        </div>
    </div>
</div>

</body>
</html>
