<?php
$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);

$db   = getDB();
$logs = $db->query("
    SELECT l.*, l.log_id AS id, u.name AS user_name, u.email AS user_email, u.role AS user_role 
    FROM audit_logs l 
    LEFT JOIN users u ON l.user_id = u.user_id 
    ORDER BY l.timestamp DESC 
    LIMIT 100
")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Audit Logs</h1>
        <p>System activity log and administrative security audit</p>
    </div>
</div>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search audit logs..." data-search-table="auditTable">
        </div>
    </div>

    <?php if (empty($logs)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-shield-halved"></i>
            <h3>No Audit Logs Found</h3>
            <p>System activities will be recorded here.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" id="auditTable">
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Affected Table</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>#<?= $log['log_id'] ?></td>
                        <td><?= formatDateTime($log['timestamp']) ?></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($log['user_name'] ?? 'System / Anonymous') ?></div>
                            <?php if (!empty($log['user_email'])): ?>
                                <div style="font-size:0.75rem;color:var(--text-secondary);"><?= htmlspecialchars($log['user_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-info"><?= htmlspecialchars($log['action']) ?></span></td>
                        <td><code><?= htmlspecialchars($log['table_affected'] ?: 'N/A') ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>