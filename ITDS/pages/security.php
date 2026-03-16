<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Manager','Auditor');

$pageTitle   = 'Security Status';
$currentPage = 'security';
$db = db();

$total       = $db->query("SELECT COUNT(*) FROM assets WHERE status='Active'")->fetchColumn();
$avInstalled = $db->query("SELECT COUNT(*) FROM assets WHERE antivirus_installed=1 AND status='Active'")->fetchColumn();
$avMissing   = $db->query("SELECT COUNT(*) FROM assets WHERE antivirus_installed=0 AND status='Active'")->fetchColumn();
$fwEnabled   = $db->query("SELECT COUNT(*) FROM assets WHERE firewall_enabled=1 AND status='Active'")->fetchColumn();
$encEnabled  = $db->query("SELECT COUNT(*) FROM assets WHERE encryption_enabled=1 AND status='Active'")->fetchColumn();
$flagged     = $db->query("SELECT COUNT(*) FROM assets WHERE is_flagged=1")->fetchColumn();

// Assets missing AV
$noAV = $db->query("SELECT a.asset_tag, a.brand, a.model, e.name AS emp_name, d.name AS dept_name
    FROM assets a
    LEFT JOIN employees e ON a.employee_id=e.id
    LEFT JOIN departments d ON e.department_id=d.id
    WHERE a.antivirus_installed=0 AND a.status='Active' LIMIT 50")->fetchAll();

// Flagged assets
$flaggedAssets = $db->query("SELECT a.*, e.name AS emp_name, d.name AS dept_name
    FROM assets a
    LEFT JOIN employees e ON a.employee_id=e.id
    LEFT JOIN departments d ON e.department_id=d.id
    WHERE a.is_flagged=1 ORDER BY a.flag_reason")->fetchAll();

// No firewall
$noFW = $db->query("SELECT a.asset_tag, a.brand, a.model, e.name AS emp_name
    FROM assets a LEFT JOIN employees e ON a.employee_id=e.id
    WHERE a.firewall_enabled=0 AND a.status='Active' LIMIT 50")->fetchAll();

// No encryption
$noEnc = $db->query("SELECT a.asset_tag, a.brand, a.model, e.name AS emp_name
    FROM assets a LEFT JOIN employees e ON a.employee_id=e.id
    WHERE a.encryption_enabled=0 AND a.status='Active' LIMIT 50")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-shield-alt"></i> Security Status</h2>
</div>

<!-- Overview Cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-title">AV Coverage</div>
        <div class="stat-value" style="color:<?= $avMissing > 0 ? 'var(--danger)' : 'var(--success)' ?>">
            <?= $total > 0 ? round($avInstalled/$total*100) : 100 ?>%
        </div>
        <div class="stat-change"><?= $avInstalled ?>/<?= $total ?> assets protected</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Firewall Enabled</div>
        <div class="stat-value" style="color:<?= ($total-$fwEnabled) > 0 ? 'var(--warning)':'var(--success)' ?>">
            <?= $total > 0 ? round($fwEnabled/$total*100) : 100 ?>%
        </div>
        <div class="stat-change"><?= $fwEnabled ?>/<?= $total ?> assets</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Encryption</div>
        <div class="stat-value"><?= $total > 0 ? round($encEnabled/$total*100) : 100 ?>%</div>
        <div class="stat-change"><?= $encEnabled ?>/<?= $total ?> encrypted</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Flagged Assets</div>
        <div class="stat-value" style="color:<?= $flagged > 0 ? 'var(--danger)':'var(--success)' ?>"><?= $flagged ?></div>
        <div class="stat-change">Lost / Stolen / Suspicious</div>
    </div>
</div>

<!-- Security Score -->
<?php
$score = 0;
if ($total > 0) {
    $score = round(($avInstalled/$total * 40) + ($fwEnabled/$total * 35) + ($encEnabled/$total * 25));
}
$scoreColor = $score >= 80 ? 'var(--success)' : ($score >= 60 ? 'var(--warning)' : 'var(--danger)');
?>
<div class="table-container" style="margin-bottom:22px;text-align:center">
    <h4>Overall Security Score</h4>
    <div style="font-size:4rem;font-weight:900;color:<?= $scoreColor ?>;margin:14px 0"><?= $score ?>%</div>
    <div class="progress-bar" style="max-width:500px;margin:0 auto;height:14px;border-radius:8px">
        <div class="progress-fill <?= $score >= 80 ? '' : ($score >= 60 ? 'progress-warning' : 'progress-danger') ?>" style="width:<?= $score ?>%"></div>
    </div>
    <p style="color:var(--text-muted);margin-top:12px">Weighted: 40% Antivirus + 35% Firewall + 25% Encryption</p>
</div>

<!-- Flagged Assets -->
<?php if ($flaggedAssets): ?>
<div class="table-container">
    <h4 style="margin-bottom:16px;color:var(--danger)"><i class="fas fa-flag"></i> Flagged Assets</h4>
    <table>
        <thead><tr><th>Asset Tag</th><th>Device</th><th>Employee</th><th>Department</th><th>Flag Reason</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($flaggedAssets as $a): ?>
        <tr style="border-left:3px solid var(--danger)">
            <td><a href="asset_view.php?id=<?= urlencode($a['id']) ?>"><strong><?= sanitize($a['asset_tag']) ?></strong></a></td>
            <td><?= sanitize($a['brand'].' '.$a['model']) ?></td>
            <td><?= sanitize($a['emp_name'] ?? '—') ?></td>
            <td><?= sanitize($a['dept_name'] ?? '—') ?></td>
            <td><span class="badge badge-danger"><?= sanitize($a['flag_reason'] ?? 'Flagged') ?></span></td>
            <td><?= sanitize($a['status']) ?></td>
            <td><a href="inventory_add.php?id=<?= urlencode($a['id']) ?>" class="action-btn" title="Edit"><i class="fas fa-edit"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- No AV -->
<?php if ($noAV): ?>
<div class="table-container">
    <h4 style="margin-bottom:16px;color:var(--danger)"><i class="fas fa-virus"></i> Assets Without Antivirus (<?= count($noAV) ?>)</h4>
    <table>
        <thead><tr><th>Asset Tag</th><th>Device</th><th>Employee</th><th>Department</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($noAV as $a): ?>
        <tr>
            <td><a href="asset_view.php?id=<?= urlencode($a['asset_tag']) ?>"><?= sanitize($a['asset_tag']) ?></a></td>
            <td><?= sanitize($a['brand'].' '.$a['model']) ?></td>
            <td><?= sanitize($a['emp_name'] ?? '—') ?></td>
            <td><?= sanitize($a['dept_name'] ?? '—') ?></td>
            <td><a href="inventory_add.php?id=<?= urlencode($a['asset_tag']) ?>" class="action-btn"><i class="fas fa-edit"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Side by side: no FW + no Enc -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">
<?php if ($noFW): ?>
<div class="table-container">
    <h4 style="margin-bottom:16px;color:var(--warning)"><i class="fas fa-fire"></i> No Firewall (<?= count($noFW) ?>)</h4>
    <table>
        <thead><tr><th>Asset Tag</th><th>Device</th><th>Employee</th></tr></thead>
        <tbody>
        <?php foreach ($noFW as $a): ?>
        <tr>
            <td><a href="asset_view.php?id=<?= urlencode($a['asset_tag']) ?>"><?= sanitize($a['asset_tag']) ?></a></td>
            <td><?= sanitize($a['brand'].' '.$a['model']) ?></td>
            <td><?= sanitize($a['emp_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php if ($noEnc): ?>
<div class="table-container">
    <h4 style="margin-bottom:16px;color:var(--warning)"><i class="fas fa-lock-open"></i> No Encryption (<?= count($noEnc) ?>)</h4>
    <table>
        <thead><tr><th>Asset Tag</th><th>Device</th><th>Employee</th></tr></thead>
        <tbody>
        <?php foreach ($noEnc as $a): ?>
        <tr>
            <td><a href="asset_view.php?id=<?= urlencode($a['asset_tag']) ?>"><?= sanitize($a['asset_tag']) ?></a></td>
            <td><?= sanitize($a['brand'].' '.$a['model']) ?></td>
            <td><?= sanitize($a['emp_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div>

<?php if (!$noAV && !$noFW && !$noEnc && !$flaggedAssets): ?>
<div class="table-container" style="text-align:center;padding:40px">
    <i class="fas fa-shield-alt" style="font-size:3rem;color:var(--success)"></i>
    <h3 style="margin-top:12px;color:var(--success)">All Clear!</h3>
    <p style="color:var(--text-muted)">All active assets are secured with antivirus, firewall, and encryption.</p>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
