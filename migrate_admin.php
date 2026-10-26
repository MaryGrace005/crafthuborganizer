<?php
/**
 * CraftHub Organizer — Admin Account Migration Script
 *
 * Web URL: http://localhost/crafthuborganizer/migrate_admin.php
 * CLI:     php migrate_admin.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Determine execution context
$isCli = (php_sapi_name() === 'cli');

// Default Admin Configuration
$defaultConfig = [
    'first_name'        => 'Super',
    'surname'           => 'Admin',
    'name'              => 'Super Admin',
    'email'             => 'admin_master@crafthub.com',
    'password'          => 'Admin@2026!',
    'phone'             => '09170009999',
    'address'           => 'CraftHub Central HQ',
    'security_question' => 'What is the name of your primary organization?',
    'security_answer'   => 'CraftHub',
];

$message = '';
$messageType = '';
$createdAdmin = null;

// Handle migration execution (CLI or POST request)
if ($isCli || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration']))) {
    $firstName        = trim($_POST['first_name']        ?? $defaultConfig['first_name']);
    $surname          = trim($_POST['surname']           ?? $defaultConfig['surname']);
    $email            = trim($_POST['email']             ?? $defaultConfig['email']);
    $rawPassword      = trim($_POST['password']          ?? $defaultConfig['password']);
    $phone            = trim($_POST['phone']             ?? $defaultConfig['phone']);
    $address          = trim($_POST['address']           ?? $defaultConfig['address']);
    $securityQuestion = trim($_POST['security_question'] ?? $defaultConfig['security_question']);
    $securityAnswer   = trim($_POST['security_answer']   ?? $defaultConfig['security_answer']);
    $overwrite        = !empty($_POST['overwrite_existing']) || ($isCli && in_array('--overwrite', $argv ?? []));

    $fullName = trim($firstName . ' ' . $surname);
    if (empty($fullName)) {
        $fullName = 'System Administrator';
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Error: A valid email address is required.";
        $messageType = "error";
    } elseif (strlen($rawPassword) < 6) {
        $message = "Error: Password must be at least 6 characters long.";
        $messageType = "error";
    } else {
        try {
            // Check if user with this email or login_email already exists
            $stmt = $db->prepare("SELECT user_id, name, email, role, status FROM users WHERE email = ? OR login_email = ? LIMIT 1");
            $stmt->execute([$email, $email]);
            $existing = $stmt->fetch();

            $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
            $secAnswerHash = password_hash(strtolower(trim($securityAnswer)), PASSWORD_DEFAULT);

            // Generate an account ID code if table / function exists
            $idCode = null;
            if (function_exists('generateAccountIdCode')) {
                try {
                    $idCode = generateAccountIdCode();
                } catch (Exception $e) {
                    $idCode = 'TH-' . str_pad((string)rand(1000, 99999), 6, '0', STR_PAD_LEFT);
                }
            }

            if ($existing) {
                if ($overwrite) {
                    // Update existing account to active admin with the new password
                    $updateStmt = $db->prepare("
                        UPDATE users 
                        SET name = ?,
                            first_name = ?,
                            middle_name = ?,
                            last_name = ?,
                            surname = ?,
                            password = ?,
                            temp_password = NULL,
                            role = 'admin',
                            status = 'active',
                            contact_no = ?,
                            address = ?,
                            security_question = ?,
                            security_answer_hash = ?
                        WHERE user_id = ?
                    ");
                    $updateStmt->execute([
                        $fullName,
                        $firstName,
                        '',
                        $surname,
                        $surname,
                        $passwordHash,
                        $phone,
                        $address,
                        $securityQuestion,
                        $secAnswerHash,
                        $existing['user_id']
                    ]);

                    logAudit((int)$existing['user_id'], 'ADMIN_MIGRATION_UPDATE', "Admin account {$email} updated via migration script", 'users');

                    $message = "Admin account for <strong>" . htmlspecialchars($email) . "</strong> already existed and has been successfully updated with the new credentials and elevated to Active Admin!";
                    $messageType = "success";
                    $createdAdmin = [
                        'user_id'  => $existing['user_id'],
                        'name'     => $fullName,
                        'email'    => $email,
                        'password' => $rawPassword,
                        'role'     => 'admin',
                        'status'   => 'active',
                        'updated'  => true
                    ];
                } else {
                    $message = "An account with email <strong>" . htmlspecialchars($email) . "</strong> already exists (ID: #{$existing['user_id']}, Role: {$existing['role']}, Status: {$existing['status']}). Check 'Overwrite / Update if exists' or specify another email.";
                    $messageType = "warning";
                }
            } else {
                // Insert new admin user
                // Dynamically build column list based on available columns in users table
                $cols = ['name', 'email', 'password', 'role', 'status', 'contact_no', 'address', 'security_question', 'security_answer_hash'];
                $vals = [$fullName, $email, $passwordHash, 'admin', 'active', $phone, $address, $securityQuestion, $secAnswerHash];
                
                // Add optional profile columns if they exist
                $testCols = [
                    'first_name'  => $firstName,
                    'middle_name' => '',
                    'last_name'   => $surname,
                    'surname'     => $surname,
                    'id_code'     => $idCode,
                    'login_email' => $email
                ];
                
                foreach ($testCols as $colName => $colVal) {
                    if ($colVal !== null && function_exists('columnExists') && columnExists($db, 'users', $colName)) {
                        $cols[] = $colName;
                        $vals[] = $colVal;
                    }
                }

                $colNamesSql = implode(', ', $cols);
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));

                $insertStmt = $db->prepare("INSERT INTO users ({$colNamesSql}) VALUES ({$placeholders})");
                $insertStmt->execute($vals);
                $newId = (int)$db->lastInsertId();

                logAudit($newId, 'ADMIN_MIGRATION_CREATE', "New admin account {$email} created via migration script", 'users');

                $message = "New admin account created successfully! You can now log in immediately.";
                $messageType = "success";
                $createdAdmin = [
                    'user_id'  => $newId,
                    'id_code'  => $idCode,
                    'name'     => $fullName,
                    'email'    => $email,
                    'password' => $rawPassword,
                    'role'     => 'admin',
                    'status'   => 'active',
                    'updated'  => false
                ];
            }
        } catch (Exception $e) {
            $message = "Database Error: " . $e->getMessage();
            $messageType = "error";
        }
    }

    if ($isCli) {
        echo "===========================================\n";
        echo "CraftHub Admin Migration CLI\n";
        echo "===========================================\n";
        echo strip_tags($message) . "\n";
        if ($createdAdmin) {
            echo "Email:    " . $createdAdmin['email'] . "\n";
            echo "Password: " . $createdAdmin['password'] . "\n";
            echo "Role:     " . $createdAdmin['role'] . "\n";
            echo "Status:   " . $createdAdmin['status'] . "\n";
        }
        echo "===========================================\n";
        exit($messageType === 'success' ? 0 : 1);
    }
}

// Fetch all current administrators for display
$existingAdmins = [];
try {
    $existingAdmins = $db->query("SELECT user_id, name, email, contact_no, role, status, created_at FROM users WHERE role = 'admin' ORDER BY user_id ASC")->fetchAll();
} catch (Exception $e) {
    // Users table might not exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Account Migration — CraftHub Organizer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-base: #0f111a;
            --bg-card: rgba(26, 29, 45, 0.85);
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(at 0% 0%, rgba(139, 92, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(6, 182, 212, 0.12) 0px, transparent 50%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
        }

        .container {
            width: 100%;
            max-width: 760px;
        }

        .card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-card);
            border-radius: 20px;
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.5);
            overflow: hidden;
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
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
            box-shadow: 0 8px 16px rgba(139, 92, 246, 0.3);
        }

        .header-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff;
        }

        .header-text p {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 0.95rem;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            line-height: 1.5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .credentials-box {
            background: rgba(0, 0, 0, 0.35);
            border: 1px dashed rgba(16, 185, 129, 0.5);
            border-radius: 12px;
            padding: 18px 20px;
            margin-top: 14px;
            font-family: monospace;
            font-size: 0.95rem;
            color: #fff;
        }

        .credentials-box div {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }

        .credentials-box div:last-child {
            margin-bottom: 0;
        }

        .credentials-box .label {
            color: var(--text-muted);
        }

        .credentials-box .value {
            color: #6ee7b7;
            font-weight: bold;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 20px;
        }

        @media (max-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            background: rgba(15, 17, 26, 0.6);
            border: 1px solid var(--border-card);
            border-radius: 10px;
            padding: 12px 14px;
            color: #fff;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: #cbd5e1;
            cursor: pointer;
            text-transform: none;
            letter-spacing: normal;
        }

        .checkbox-label input {
            accent-color: var(--primary);
            width: 16px;
            height: 16px;
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
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: #fff;
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.4);
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.5);
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

        .existing-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 28px;
            font-size: 0.88rem;
        }

        .existing-table th {
            text-align: left;
            padding: 10px 14px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-card);
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .existing-table td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            color: #e2e8f0;
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .links {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border-card);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <div class="header-icon">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div class="header-text">
                <h1>Admin Account Migration</h1>
                <p>CraftHub Organizer — Provision or Update an Administrator Account</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : ($messageType === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark') ?>" style="margin-top: 3px;"></i>
                <div>
                    <?= $message ?>
                    <?php if ($createdAdmin): ?>
                        <div class="credentials-box">
                            <div><span class="label">Email:</span> <span class="value"><?= htmlspecialchars($createdAdmin['email']) ?></span></div>
                            <div><span class="label">Password:</span> <span class="value"><?= htmlspecialchars($createdAdmin['password']) ?></span></div>
                            <div><span class="label">Role:</span> <span class="value"><?= htmlspecialchars($createdAdmin['role']) ?></span></div>
                            <div><span class="label">Status:</span> <span class="value"><?= htmlspecialchars($createdAdmin['status']) ?></span></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="run_migration" value="1">

            <div class="form-grid">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" value="<?= htmlspecialchars($_POST['first_name'] ?? $defaultConfig['first_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Surname</label>
                    <input type="text" name="surname" value="<?= htmlspecialchars($_POST['surname'] ?? $defaultConfig['surname']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Admin Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $defaultConfig['email']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="text" name="password" value="<?= htmlspecialchars($_POST['password'] ?? $defaultConfig['password']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $defaultConfig['phone']) ?>">
                </div>

                <div class="form-group">
                    <label>Address / Branch</label>
                    <input type="text" name="address" value="<?= htmlspecialchars($_POST['address'] ?? $defaultConfig['address']) ?>">
                </div>

                <div class="form-group">
                    <label>Security Question</label>
                    <input type="text" name="security_question" value="<?= htmlspecialchars($_POST['security_question'] ?? $defaultConfig['security_question']) ?>">
                </div>

                <div class="form-group">
                    <label>Security Answer</label>
                    <input type="text" name="security_answer" value="<?= htmlspecialchars($_POST['security_answer'] ?? $defaultConfig['security_answer']) ?>">
                </div>

                <div class="form-group full-width">
                    <label class="checkbox-label">
                        <input type="checkbox" name="overwrite_existing" value="1" <?= isset($_POST['overwrite_existing']) ? 'checked' : '' ?>>
                        Overwrite / update password & elevate role if this email already exists
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-bolt"></i> Run Admin Migration Script
            </button>
        </form>

        <?php if (!empty($existingAdmins)): ?>
            <div style="margin-top: 32px;">
                <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 12px;">
                    <i class="fa-solid fa-users-gear"></i> Existing Admin Accounts in Database (<?= count($existingAdmins) ?>)
                </h3>
                <table class="existing-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($existingAdmins as $adm): ?>
                            <tr>
                                <td>#<?= $adm['user_id'] ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($adm['name']) ?></td>
                                <td><?= htmlspecialchars($adm['email']) ?></td>
                                <td><span class="badge-active"><?= htmlspecialchars($adm['status']) ?></span></td>
                                <td style="color: var(--text-muted); font-size: 0.8rem;"><?= htmlspecialchars($adm['created_at'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="links">
            <a href="<?= APP_URL ?>/login.php" class="btn btn-outline">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Go to Login Page
            </a>
            <a href="<?= APP_URL ?>/migrate.php" class="btn btn-outline">
                <i class="fa-solid fa-database"></i> Full DB Migration Runner
            </a>
        </div>
    </div>
</div>

</body>
</html>
