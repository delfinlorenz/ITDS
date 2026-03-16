<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Manager','Auditor');

$pageTitle   = 'Forecasting';
$currentPage = 'forecast';
$db = db();

// Assets expiring warranty in next 90 days
$warrantyExpiring = $db->query("
    SELECT a.asset_tag, a.brand, a.model, a.warranty_expiry, a.purchase_cost,
           e.name AS emp_name, d.name AS dept_name,
           DATEDIFF(a.warranty_expiry, CURDATE()) AS days_left
    FROM   assets a
    LEFT JOIN employees   e ON a.employee_id   = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE  a.warranty_expiry IS NOT NULL
      AND  a.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER  BY a.warranty_expiry ASC
    LIMIT  50
")->fetchAll();

// Assets with age >= 4 years (replacement candidates)
$agingAssets = $db->query("
    SELECT a.asset_tag, a.brand, a.model, a.purchase_date, a.purchase_cost, a.lifecycle_state,
           e.name AS emp_name, d.name AS dept_name,
           TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) AS age_years
    FROM   assets a
    LEFT JOIN employees   e ON a.employee_id   = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE  a.purchase_date IS NOT NULL
      AND  TIMESTAMPDIFF(YEAR, a.purchase_date, CURDATE()) >= 4
      AND  a.status = 'Active'
    ORDER  BY a.purchase_date ASC
    LIMIT  50
")->fetchAll();

// Budget summary by year
$budgetByYear = $db->query("
    SELECT YEAR(purchase_date) AS yr,
           COUNT(*)            AS cnt,
           SUM(purchase_cost)  AS total
    FROM   assets
    WHERE  purchase_date IS NOT NULL
    GROUP  BY YEAR(purchase_date)
    ORDER  BY yr DESC
    LIMIT  5
")->fetchAll();

// Budget by department
$budgetByDept = $db->query("
    SELECT d.name                       AS dept,
           COUNT(a.id)                  AS cnt,
           COALESCE(SUM(a.purchase_cost), 0) AS total
    FROM   assets a
    LEFT JOIN employees   e ON a.employee_id   = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    GROUP  BY d.name
    ORDER  BY total DESC
    LIMIT  10
")->fetchAll();

// Maintenance cost by month (last 12 months)
// SELECT, GROUP BY, and ORDER BY all use the IDENTICAL expression so
// only_full_group_by has nothing to complain about.
// The '%Y-%m' string is also sortable chronologically.
$maintCostByMonth = $db->query("
    SELECT DATE_FORMAT(scheduled_date, '%Y-%m') AS mo,
           SUM(cost)                            AS total
    FROM   maintenance_tasks
    WHERE  status = 'Completed'
      AND  scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP  BY DATE_FORMAT(scheduled_date, '%Y-%m')
    ORDER  BY DATE_FORMAT(scheduled_date, '%Y-%m') ASC
")->fetchAll();

// Licenses expiring soon
$licensesExpiring = $db->query("
    SELECT name, vendor, total_seats, used_seats, expiration_date,
           DATEDIFF(expiration_date, CURDATE()) AS days_left
    FROM   software_licenses
    WHERE  expiration_date IS NOT NULL
      AND  expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER  BY expiration_date ASC
")->fetchAll();

// Total current book value (depreciated)
$allAssets = $db->query("
    SELECT purchase_cost, purchase_date
    FROM   assets
    WHERE  purchase_cost > 0 AND purchase_date IS NOT NULL
")->fetchAll();

$totalBookValue = 0;
foreach ($allAssets as $a) {
    $totalBookValue += calculateDepreciation($a['purchase_cost'], $a['purchase_date'], 5);
}

$totalOriginal = (float)$db->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-chart-line"></i> Forecasting &amp; Budget</h2>
</div>

<!-- Summary Cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-title">Original Asset Value</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($totalOriginal) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Current Book Value</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($totalBookValue) ?></div>
        <?php if ($totalOriginal > 0): ?>
        <div class="stat-change"><?= round(($totalBookValue / $totalOriginal) * 100) ?>% of original</div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="stat-title">Warranty Expiring (90d)</div>
        <div class="stat-value"><?= count($warrantyExpiring) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Assets Age 4+ Years</div>
        <div class="stat-value"><?= count($agingAssets) ?></div>
    </div>
</div>

<!-- Annual Procurement -->
<div class="table-container">
    <h4 style="margin-bottom:16px"><i class="fas fa-calendar"></i> Annual Procurement Summary</h4>
    <table>
        <thead>
            <tr><th>Year</th><th>Assets Purchased</th><th>Total Spend</th><th>Avg per Asset</th></tr>
        </thead>
        <tbody>
        <?php foreach ($budgetByYear as $b): ?>
        <tr>
            <td><strong><?= (int)$b['yr'] ?></strong></td>
            <td><?= (int)$b['cnt'] ?></td>
            <td><?= peso($b['total']) ?></td>
            <td><?= peso($b['cnt'] > 0 ? $b['total'] / $b['cnt'] : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($budgetByYear)): ?>
        <tr><td colspan="4"><div class="empty-state"><p>No procurement data.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Budget by Dept + Maintenance Cost -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">

  <div class="table-container">
      <h4 style="margin-bottom:16px"><i class="fas fa-building"></i> Budget by Department</h4>
      <table>
          <thead><tr><th>Department</th><th>Assets</th><th>Total Value</th></tr></thead>
          <tbody>
          <?php foreach ($budgetByDept as $d): ?>
          <tr>
              <td><?= sanitize($d['dept'] ?? 'Unassigned') ?></td>
              <td><?= (int)$d['cnt'] ?></td>
              <td><?= peso($d['total']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($budgetByDept)): ?>
          <tr><td colspan="3"><div class="empty-state"><p>No data.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
      </table>
  </div>

  <div class="table-container">
      <h4 style="margin-bottom:16px"><i class="fas fa-tools"></i> Maintenance Cost (Last 12 Mo)</h4>
      <table>
          <thead><tr><th>Month</th><th>Total Cost</th></tr></thead>
          <tbody>
          <?php foreach ($maintCostByMonth as $m): ?>
          <tr>
              <td><?= date('M Y', strtotime($m['mo'] . '-01')) ?></td>
              <td><?= peso($m['total']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($maintCostByMonth)): ?>
          <tr><td colspan="2"><div class="empty-state"><p>No data.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
      </table>
  </div>

</div>

<!-- Warranty Expiring -->
<div class="table-container">
    <h4 style="margin-bottom:16px">
        <i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i>
        Warranties Expiring in 90 Days
    </h4>
    <table>
        <thead>
            <tr><th>Asset Tag</th><th>Device</th><th>Employee</th><th>Department</th><th>Expiry Date</th><th>Days Left</th><th>Cost</th></tr>
        </thead>
        <tbody>
        <?php foreach ($warrantyExpiring as $a): ?>
        <tr>
            <td><a href="asset_view.php?id=<?= urlencode($a['asset_tag']) ?>"><?= sanitize($a['asset_tag']) ?></a></td>
            <td><?= sanitize($a['brand'] . ' ' . $a['model']) ?></td>
            <td><?= sanitize($a['emp_name']  ?? '—') ?></td>
            <td><?= sanitize($a['dept_name'] ?? '—') ?></td>
            <td><?= sanitize($a['warranty_expiry']) ?></td>
            <td>
                <span class="badge badge-<?= $a['days_left'] < 30 ? 'danger' : 'warning' ?>">
                    <?= (int)$a['days_left'] ?>d
                </span>
            </td>
            <td><?= peso($a['purchase_cost']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($warrantyExpiring)): ?>
        <tr><td colspan="7">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--success)"></i>
                <p>No warranties expiring in the next 90 days.</p>
            </div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Aging Assets -->
<div class="table-container">
    <h4 style="margin-bottom:16px">
        <i class="fas fa-clock" style="color:var(--danger)"></i>
        Aging Assets (4+ Years) — Replacement Candidates
    </h4>
    <table>
        <thead>
            <tr><th>Asset Tag</th><th>Device</th><th>Employee</th><th>Department</th><th>Purchase Date</th><th>Age</th><th>Original Cost</th><th>Book Value</th></tr>
        </thead>
        <tbody>
        <?php foreach ($agingAssets as $a): ?>
        <tr>
            <td><a href="asset_view.php?id=<?= urlencode($a['asset_tag']) ?>"><?= sanitize($a['asset_tag']) ?></a></td>
            <td><?= sanitize($a['brand'] . ' ' . $a['model']) ?></td>
            <td><?= sanitize($a['emp_name']  ?? '—') ?></td>
            <td><?= sanitize($a['dept_name'] ?? '—') ?></td>
            <td><?= sanitize($a['purchase_date'] ?? '—') ?></td>
            <td>
                <span class="badge badge-<?= $a['age_years'] >= 6 ? 'danger' : 'warning' ?>">
                    <?= (int)$a['age_years'] ?>y
                </span>
            </td>
            <td><?= peso($a['purchase_cost']) ?></td>
            <td><?= peso(calculateDepreciation($a['purchase_cost'], $a['purchase_date'], 5)) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($agingAssets)): ?>
        <tr><td colspan="8">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="color:var(--success)"></i>
                <p>No aging assets found.</p>
            </div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- License Expiry -->
<?php if (!empty($licensesExpiring)): ?>
<div class="table-container">
    <h4 style="margin-bottom:16px">
        <i class="fas fa-key" style="color:var(--warning)"></i>
        Software Licenses Expiring in 90 Days
    </h4>
    <table>
        <thead>
            <tr><th>Software</th><th>Vendor</th><th>Seats</th><th>Expiry</th><th>Days Left</th></tr>
        </thead>
        <tbody>
        <?php foreach ($licensesExpiring as $l): ?>
        <tr>
            <td><?= sanitize($l['name']) ?></td>
            <td><?= sanitize($l['vendor']) ?></td>
            <td><?= (int)$l['used_seats'] ?> / <?= (int)$l['total_seats'] ?></td>
            <td><?= sanitize($l['expiration_date']) ?></td>
            <td>
                <span class="badge badge-<?= $l['days_left'] < 30 ? 'danger' : 'warning' ?>">
                    <?= (int)$l['days_left'] ?>d
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
