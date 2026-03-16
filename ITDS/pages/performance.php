<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Technician','Manager');

$pageTitle   = 'Performance Monitoring';
$currentPage = 'performance';
$db = db();

// Maintenance stats
$maintTotal    = $db->query("SELECT COUNT(*) FROM maintenance_tasks")->fetchColumn();
$maintCompleted= $db->query("SELECT COUNT(*) FROM maintenance_tasks WHERE status='Completed'")->fetchColumn();
$maintOverdue  = $db->query("SELECT COUNT(*) FROM maintenance_tasks WHERE status='Scheduled' AND scheduled_date < CURDATE()")->fetchColumn();
$avgResTime    = $db->query("SELECT AVG(DATEDIFF(completed_date, scheduled_date)) FROM maintenance_tasks WHERE status='Completed' AND completed_date IS NOT NULL")->fetchColumn();

// Helpdesk
$ticketsTotal  = $db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$ticketsPend   = $db->query("SELECT COUNT(*) FROM tickets WHERE status='Open' OR status='Pending'")->fetchColumn();
$ticketsRes    = $db->query("SELECT COUNT(*) FROM tickets WHERE status='Resolved'")->fetchColumn();
$avgTicketRes  = $db->query("SELECT AVG(DATEDIFF(resolved_date, created_at)) FROM tickets WHERE status='Resolved' AND resolved_date IS NOT NULL")->fetchColumn();

// Departments with most issues
$deptIssues = $db->query("SELECT d.name AS dept, COUNT(t.id) AS cnt
    FROM tickets t
    LEFT JOIN assets a ON t.asset_id=a.id
    LEFT JOIN employees e ON a.employee_id=e.id
    LEFT JOIN departments d ON e.department_id=d.id
    WHERE d.name IS NOT NULL
    GROUP BY d.name ORDER BY cnt DESC LIMIT 8")->fetchAll();

// Top assets by maintenance count
$topMaint = $db->query("SELECT a.asset_tag, a.brand, a.model, e.name AS emp_name,
    COUNT(mt.id) AS task_count, SUM(mt.cost) AS total_cost
    FROM maintenance_tasks mt
    JOIN assets a ON mt.asset_id = a.id
    LEFT JOIN employees e ON a.employee_id=e.id
    GROUP BY a.id ORDER BY task_count DESC LIMIT 10")->fetchAll();

// Technician workload
$techWorkload = $db->query("SELECT assigned_to, COUNT(*) AS tasks, SUM(cost) AS revenue,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed
    FROM maintenance_tasks WHERE assigned_to IS NOT NULL AND assigned_to != ''
    GROUP BY assigned_to ORDER BY tasks DESC LIMIT 8")->fetchAll();

// Recent tickets with SLA
$recentTickets = $db->query("SELECT t.*, a.asset_tag, t.employee_name AS emp_name
    FROM tickets t
    LEFT JOIN assets a ON t.asset_id=a.id
    ORDER BY t.created_at DESC LIMIT 20")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-tachometer-alt"></i> Performance Monitoring</h2>
</div>

<!-- KPI Cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-title">Maintenance Completion Rate</div>
        <div class="stat-value" style="color:<?= $maintTotal > 0 && ($maintCompleted/$maintTotal) >= .8 ? 'var(--success)' : 'var(--warning)' ?>">
            <?= $maintTotal > 0 ? round($maintCompleted/$maintTotal*100) : 0 ?>%
        </div>
        <div class="stat-change"><?= $maintCompleted ?>/<?= $maintTotal ?> tasks</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Overdue Tasks</div>
        <div class="stat-value" style="color:<?= $maintOverdue > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= $maintOverdue ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Ticket Resolution Rate</div>
        <div class="stat-value" style="color:var(--success)">
            <?= $ticketsTotal > 0 ? round($ticketsRes/$ticketsTotal*100) : 0 ?>%
        </div>
        <div class="stat-change"><?= $ticketsRes ?>/<?= $ticketsTotal ?> resolved</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Avg Ticket Resolution</div>
        <div class="stat-value"><?= $avgTicketRes ? round($avgTicketRes, 1) : '—' ?><span style="font-size:1rem"> days</span></div>
    </div>
</div>

<!-- Charts row -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px">
    <div class="table-container">
        <h4 style="margin-bottom:14px">Tickets by Department</h4>
        <canvas id="deptChart" height="220"></canvas>
    </div>
    <div class="table-container">
        <h4 style="margin-bottom:14px">Maintenance Workload by Technician</h4>
        <canvas id="techChart" height="220"></canvas>
    </div>
</div>

<!-- Top Problem Assets -->
<div class="table-container">
    <h4 style="margin-bottom:16px"><i class="fas fa-exclamation-triangle"></i> Top Assets by Maintenance Count</h4>
    <table>
        <thead><tr><th>Asset Tag</th><th>Device</th><th>Employee</th><th>Total Tasks</th><th>Total Cost</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($topMaint as $a): ?>
        <tr>
            <td><a href="asset_view.php?id=<?= urlencode($a['asset_tag']) ?>"><strong><?= sanitize($a['asset_tag']) ?></strong></a></td>
            <td><?= sanitize($a['brand'].' '.$a['model']) ?></td>
            <td><?= sanitize($a['emp_name'] ?? '—') ?></td>
            <td>
                <span class="badge badge-<?= $a['task_count'] >= 5 ? 'danger' : ($a['task_count'] >= 3 ? 'warning' : 'info') ?>">
                    <?= $a['task_count'] ?>
                </span>
            </td>
            <td><?= peso($a['total_cost']) ?></td>
            <td><a href="maintenance.php?search=<?= urlencode($a['asset_tag']) ?>" class="action-btn"><i class="fas fa-tools"></i></a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($topMaint)): ?><tr><td colspan="6"><div class="empty-state"><p>No maintenance data.</p></div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Technician Workload Table -->
<div class="table-container">
    <h4 style="margin-bottom:16px"><i class="fas fa-user-cog"></i> Technician Performance</h4>
    <table>
        <thead><tr><th>Technician</th><th>Total Tasks</th><th>Completed</th><th>Completion Rate</th><th>Total Cost</th></tr></thead>
        <tbody>
        <?php foreach ($techWorkload as $t): ?>
        <tr>
            <td><?= sanitize($t['assigned_to']) ?></td>
            <td><?= $t['tasks'] ?></td>
            <td><?= $t['completed'] ?></td>
            <td>
                <?php $rate = $t['tasks'] > 0 ? round($t['completed']/$t['tasks']*100) : 0; ?>
                <div class="progress-bar" style="width:120px;display:inline-block;vertical-align:middle">
                    <div class="progress-fill <?= $rate < 60 ? 'progress-danger' : ($rate < 80 ? 'progress-warning' : '') ?>"
                         style="width:<?= $rate ?>%"></div>
                </div>
                <?= $rate ?>%
            </td>
            <td><?= peso($t['revenue']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($techWorkload)): ?><tr><td colspan="5"><div class="empty-state"><p>No data.</p></div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Recent Tickets -->
<div class="table-container">
    <h4 style="margin-bottom:16px"><i class="fas fa-ticket-alt"></i> Recent Tickets</h4>
    <table>
        <thead><tr><th>ID</th><th>Title</th><th>Asset</th><th>Employee</th><th>Priority</th><th>Status</th><th>Created</th><th>Age</th></tr></thead>
        <tbody>
        <?php foreach ($recentTickets as $t):
            $ageDays = (time() - strtotime($t['created_at'])) / 86400;
        ?>
        <tr>
            <td><?= sanitize($t['id']) ?></td>
            <td><?= sanitize($t['title']) ?></td>
            <td><?= sanitize($t['asset_tag'] ?? '—') ?></td>
            <td><?= sanitize($t['emp_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $t['priority']==='High'||$t['priority']==='Critical'?'danger':($t['priority']==='Medium'?'warning':'info') ?>"><?= sanitize($t['priority']) ?></span></td>
            <td><span class="badge badge-<?= $t['status']==='Resolved'?'success':($t['status']==='In Progress'?'warning':'info') ?>"><?= sanitize($t['status']) ?></span></td>
            <td><?= date('M d', strtotime($t['created_at'])) ?></td>
            <td>
                <?php if ($t['status'] !== 'Resolved'): ?>
                <span style="color:<?= $ageDays > 3 ? 'var(--danger)' : 'var(--text-muted)' ?>">
                    <?= round($ageDays) ?>d
                </span>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentTickets)): ?><tr><td colspan="8"><div class="empty-state"><p>No tickets.</p></div></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#a8b2c1' : '#6b7280';

    // Dept chart
    const deptData = <?= json_encode(array_map(fn($r) => ['dept' => $r['dept'], 'cnt' => (int)$r['cnt']], $deptIssues)) ?>;
    if (deptData.length) {
        new Chart(document.getElementById('deptChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: deptData.map(d => d.dept || 'Unassigned'),
                datasets: [{ label: 'Tickets', data: deptData.map(d => d.cnt),
                    backgroundColor: '#6366f1', borderRadius: 6 }]
            },
            options: { responsive: true, plugins: { legend: { display: false } },
                scales: { x: { ticks: { color: textColor } }, y: { ticks: { color: textColor } } } }
        });
    }

    // Tech chart
    const techData = <?= json_encode(array_map(fn($r) => ['name' => $r['assigned_to'], 'tasks' => (int)$r['tasks']], $techWorkload)) ?>;
    if (techData.length) {
        new Chart(document.getElementById('techChart').getContext('2d'), {
            type: 'horizontalBar' in Chart.defaults ? 'horizontalBar' : 'bar',
            data: {
                labels: techData.map(t => t.name),
                datasets: [{ label: 'Tasks', data: techData.map(t => t.tasks),
                    backgroundColor: '#10b981', borderRadius: 6 }]
            },
            options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } },
                scales: { x: { ticks: { color: textColor } }, y: { ticks: { color: textColor } } } }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
