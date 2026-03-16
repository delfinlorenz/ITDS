<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Manager','Auditor');

$pageTitle   = 'Audit Trail';
$currentPage = 'audit';
$db = db();

$search = $_GET['search'] ?? '';
$userFilter = $_GET['user'] ?? '';

$where = []; $params = [];
if ($search) { $where[] = "(action LIKE ? OR details LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like]); }
if ($userFilter) { $where[] = "user_name=?"; $params[] = $userFilter; }
$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

$logs = $db->prepare("SELECT * FROM audit_log $whereSQL ORDER BY created_at DESC LIMIT 500");
$logs->execute($params);
$logs = $logs->fetchAll();

$users = $db->query("SELECT DISTINCT user_name FROM audit_log ORDER BY user_name")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-history"></i> Audit Trail</h2>
    <button class="btn btn-info" onclick="exportTableToExcel('auditTable','audit_export')">
        <i class="fas fa-file-excel"></i> Export
    </button>
</div>

<form method="GET">
<div class="filter-bar">
    <input class="filter-select" name="search" placeholder="Search actions or details..." value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="user">
        <option value="">All Users</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= sanitize($u) ?>" <?= $userFilter===$u?'selected':'' ?>><?= sanitize($u) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="audit.php" class="btn btn-secondary">Clear</a>
</div>
</form>

<div class="table-container">
    <p style="color:var(--text-muted);margin-bottom:14px;font-size:.9rem">Showing <?= count($logs) ?> records</p>
    <table id="auditTable">
        <thead>
            <tr><th>Timestamp</th><th>User</th><th>Role</th><th>Company</th><th>Action</th><th>Details</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
            <td><strong><?= sanitize($log['user_name']) ?></strong></td>
            <td><span class="badge badge-info"><?= sanitize($log['user_role']) ?></span></td>
            <td><?= sanitize($log['company'] ?? '—') ?></td>
            <td><?= sanitize($log['action']) ?></td>
            <td><?= sanitize($log['details'] ?? '') ?></td>
            <td><small><?= sanitize($log['ip_address'] ?? '—') ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-history"></i><p>No audit records found.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
