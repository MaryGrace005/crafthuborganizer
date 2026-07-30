$logs = $pdo->query("SELECT l.*, u.name FROM audit_logs l JOIN users u ON l.user_id=u.user_id ORDER BY l.timestamp DESC")->fetchAll();
// Display in table