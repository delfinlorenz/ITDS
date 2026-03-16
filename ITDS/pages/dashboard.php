<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
$db = db();

// ── Get ALL asset counts — LOWER+TRIM so Active/active/ACTIVE all match ──────
$row = $db->query("
    SELECT
        COUNT(*)                                                             AS total,
        SUM(CASE WHEN LOWER(TRIM(status))='active'      THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN LOWER(TRIM(status))='maintenance' THEN 1 ELSE 0 END) AS maint,
        SUM(CASE WHEN LOWER(TRIM(status))='retired'     THEN 1 ELSE 0 END) AS retired,
        SUM(CASE WHEN LOWER(TRIM(status))='spare'       THEN 1 ELSE 0 END) AS spare,
        COALESCE(SUM(purchase_cost), 0)                                     AS total_value,
        SUM(CASE WHEN warranty_expiry IS NOT NULL
                  AND warranty_expiry BETWEEN CURDATE()
                  AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 THEN 1 ELSE 0 END)                                         AS warranty_exp
    FROM assets
")->fetch(PDO::FETCH_ASSOC);

$total       = intval($row['total']        ?? 0);
$active      = intval($row['active']       ?? 0);
$maint       = intval($row['maint']        ?? 0);
$retired     = intval($row['retired']      ?? 0);
$spare       = intval($row['spare']        ?? 0);
$totalValue  = floatval($row['total_value'] ?? 0);
$warrantyExp = intval($row['warranty_exp']  ?? 0);

// DEBUG — remove after confirming correct counts
// Uncomment next line temporarily to see raw status values:
// die('<pre>'.print_r($db->query("SELECT DISTINCT status, COUNT(*) c FROM assets GROUP BY status")->fetchAll(PDO::FETCH_ASSOC),true).'</pre>');

// ── Other stats ────────────────────────────────────────────────────────────────
$r = $db->query("SELECT COALESCE(SUM(cost),0) FROM maintenance_tasks WHERE status='Completed'")->fetch(PDO::FETCH_NUM);
$maintCost = floatval($r[0] ?? 0);

$r = $db->query("SELECT COUNT(*) FROM supplies WHERE status='Available'")->fetch(PDO::FETCH_NUM);
$supplyAvail = intval($r[0] ?? 0);

$r = $db->query("SELECT COALESCE(SUM(purchase_cost),0) FROM supplies")->fetch(PDO::FETCH_NUM);
$supplyValue = floatval($r[0] ?? 0);

$r = $db->query("SELECT COUNT(*) FROM maintenance_tasks WHERE status != 'Completed'")->fetch(PDO::FETCH_NUM);
$pendingTasks = intval($r[0] ?? 0);

$r = $db->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('Resolved','Closed')")->fetch(PDO::FETCH_NUM);
$openTickets = intval($r[0] ?? 0);

$r = $db->query("SELECT COUNT(DISTINCT emp_id) FROM employees WHERE is_active=1")->fetch(PDO::FETCH_NUM);
$employees = intval($r[0] ?? 0);

// ── Chart data ─────────────────────────────────────────────────────────────────
$statusRows = $db->query("
    SELECT status, COUNT(*) AS cnt FROM assets GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$deptRows = $db->query("
    SELECT COALESCE(d.name,'Unassigned') AS name, COUNT(a.id) AS cnt
    FROM   assets a
    LEFT JOIN employees   e ON a.employee_id   = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    GROUP  BY d.name ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$osRows = $db->query("
    SELECT COALESCE(NULLIF(operating_system,''),'Unknown') AS os, COUNT(*) AS cnt
    FROM   assets GROUP BY operating_system ORDER BY cnt DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = json_encode(array_column($statusRows, 'status'));
$statusData   = json_encode(array_map('intval', array_column($statusRows, 'cnt')));
$deptLabels   = json_encode(array_column($deptRows, 'name'));
$deptData     = json_encode(array_map('intval', array_column($deptRows, 'cnt')));
$osLabels     = json_encode(array_column($osRows, 'os'));
$osData       = json_encode(array_map('intval', array_column($osRows, 'cnt')));

// ── Upcoming maintenance ───────────────────────────────────────────────────────
$upcoming = $db->query("
    SELECT mt.*, a.asset_tag, e.name AS emp_name
    FROM   maintenance_tasks mt
    LEFT JOIN assets    a ON mt.asset_id    = a.id
    LEFT JOIN employees e ON a.employee_id  = e.id
    WHERE  mt.status != 'Completed'
    ORDER  BY mt.scheduled_date ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent audit ───────────────────────────────────────────────────────────────
$recentAudit = $db->query("
    SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Flash message hook -->
<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg']  ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<!-- ── Stat Grid ─────────────────────────────────────────────────────────────── -->
<div class="stat-grid">

    <a class="stat-card" href="inventory.php">
        <div class="stat-title">Total Computers</div>
        <div class="stat-value"><?= $total ?></div>
    </a>



    <a class="stat-card" href="inventory.php?status=Maintenance">
        <div class="stat-title">In Maintenance</div>
        <div class="stat-value"><?= $maint ?></div>
    </a>

    <a class="stat-card" href="inventory.php?status=Retired">
        <div class="stat-title">Retired</div>
        <div class="stat-value"><?= $retired ?></div>
    </a>

    <a class="stat-card" href="inventory.php?status=Spare">
        <div class="stat-title">Spare</div>
        <div class="stat-value"><?= $spare ?></div>
    </a>

    <a class="stat-card" href="inventory.php">
        <div class="stat-title">Warranty &le; 30d</div>
        <div class="stat-value"><?= $warrantyExp ?></div>
    </a>

    <a class="stat-card" href="employees.php">
        <div class="stat-title">Employees</div>
        <div class="stat-value"><?= $employees ?></div>
    </a>

    <div class="stat-card">
        <div class="stat-title">Asset Value</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($totalValue) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-title">Maint Cost</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($maintCost) ?></div>
    </div>

    <a class="stat-card" href="supplies.php?status=Available">
        <div class="stat-title">Available Supplies</div>
        <div class="stat-value"><?= $supplyAvail ?></div>
    </a>

    <div class="stat-card">
        <div class="stat-title">Supply Value</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($supplyValue) ?></div>
    </div>

    <a class="stat-card" href="maintenance.php">
        <div class="stat-title">Pending Tasks</div>
        <div class="stat-value"><?= $pendingTasks ?></div>
    </a>

    <a class="stat-card" href="helpdesk.php">
        <div class="stat-title">Open Tickets</div>
        <div class="stat-value"><?= $openTickets ?></div>
    </a>

</div>

<!-- ── Charts ─────────────────────────────────────────────────────────────────── -->
<div class="charts-row">
    <div class="chart-card">
        <h4>Devices by Status</h4>
        <canvas id="statusChart"></canvas>
    </div>
    <div class="chart-card">
        <h4>Devices by Department</h4>
        <canvas id="deptChart"></canvas>
    </div>
    <div class="chart-card">
        <h4>OS Distribution</h4>
        <canvas id="osChart"></canvas>
    </div>
</div>

<!-- ── Upcoming Maintenance ───────────────────────────────────────────────────── -->
<div class="table-container">
    <div class="table-header">
        <h4>Upcoming Maintenance</h4>
        <a class="btn btn-sm" href="maintenance.php"><i class="fas fa-tools"></i> View All</a>
    </div>
    <?php if (empty($upcoming)): ?>
    <div class="empty-state">
        <i class="fas fa-check-circle"></i>
        <p>No pending maintenance tasks</p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr><th>Task</th><th>Asset</th><th>Employee</th><th>Date</th><th>Assigned To</th><th>Priority</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($upcoming as $t): ?>
        <tr>
            <td><strong><?= sanitize($t['task_name']) ?></strong></td>
            <td><a href="asset_view.php?id=<?= intval($t['asset_id']) ?>"><?= sanitize($t['asset_tag'] ?? '—') ?></a></td>
            <td><?= sanitize($t['emp_name'] ?? '—') ?></td>
            <td><?= sanitize($t['scheduled_date']) ?></td>
            <td><?= !empty($t['assigned_to']) ? sanitize($t['assigned_to']) : '—' ?></td>
            <td>
                <span class="badge badge-<?= $t['priority']==='High'?'danger':($t['priority']==='Medium'?'warning':'success') ?>">
                    <?= sanitize($t['priority']) ?>
                </span>
            </td>
            <td>
                <span class="status-badge status-<?= strtolower(str_replace(' ','-',$t['status'])) ?>">
                    <?= sanitize($t['status']) ?>
                </span>
            </td>
            <td>
                <?php if ($t['status'] !== 'Completed'): ?>
                <form method="POST" action="../api/maintenance.php" style="display:inline">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="id" value="<?= intval($t['id']) ?>">
                    <button type="submit" class="action-btn" title="Mark Complete">
                        <i class="fas fa-check-circle"></i>
                    </button>
                </form>
                <?php endif; ?>
                <a class="action-btn" href="asset_view.php?id=<?= intval($t['asset_id']) ?>" title="View Asset">
                    <i class="fas fa-eye"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── Recent Activity ────────────────────────────────────────────────────────── -->
<div class="table-container">
    <div class="table-header">
        <h4>Recent Activity</h4>
        <a class="btn btn-sm btn-secondary" href="audit.php">Full Audit Trail</a>
    </div>
    <table>
        <thead>
            <tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentAudit as $a): ?>
        <tr>
            <td style="white-space:nowrap;font-size:12px;color:#777"><?= date('M d, H:i', strtotime($a['created_at'])) ?></td>
            <td><?= sanitize($a['user_name']) ?></td>
            <td><span class="badge badge-info"><?= sanitize($a['user_role']) ?></span></td>
            <td><?= sanitize($a['action']) ?></td>
            <td style="font-size:12px;color:#777"><?= sanitize($a['details'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentAudit)): ?>
        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-history"></i><p>No recent activity</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const colors6 = ['#2563eb','#7c3aed','#059669','#d97706','#dc2626','#0891b2'];

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?= $statusLabels ?>,
        datasets: [{ data: <?= $statusData ?>, backgroundColor: colors6, borderWidth: 2 }]
    },
    options: { plugins: { legend: { position: 'bottom' } }, cutout: '60%' }
});

new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?= $deptLabels ?>,
        datasets: [{ label: 'Devices', data: <?= $deptData ?>, backgroundColor: '#2563eb', borderRadius: 5 }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

new Chart(document.getElementById('osChart'), {
    type: 'pie',
    data: {
        labels: <?= $osLabels ?>,
        datasets: [{ data: <?= $osData ?>, backgroundColor: colors6 }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>