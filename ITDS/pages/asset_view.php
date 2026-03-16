<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Asset Detail';
$currentPage = 'inventory';
$db = db();

$id = trim($_GET['id'] ?? '');
if (!$id) { header('Location: inventory.php'); exit; }

// ── POST: Add Software ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_software') {
    $db->prepare("
        INSERT INTO installed_software (asset_id, name, version, license_key, install_date)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        intval($id),
        trim($_POST['name']),
        trim($_POST['version']     ?? ''),
        trim($_POST['license_key'] ?? ''),
        !empty($_POST['install_date']) ? $_POST['install_date'] : date('Y-m-d'),
    ]);
    addAuditLog('Added Software', "Asset ID: $id — " . trim($_POST['name']));
    $_SESSION['flash_msg']  = 'Software added successfully.';
    $_SESSION['flash_type'] = 'success';
    header("Location: asset_view.php?id=$id#software"); exit;
}

// ── POST: Edit Software ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_software') {
    $swId = intval($_POST['sw_id']);
    $db->prepare("
        UPDATE installed_software
        SET name=?, version=?, license_key=?, install_date=?
        WHERE id=? AND asset_id=?
    ")->execute([
        trim($_POST['name']),
        trim($_POST['version']     ?? ''),
        trim($_POST['license_key'] ?? ''),
        !empty($_POST['install_date']) ? $_POST['install_date'] : null,
        $swId,
        intval($id),
    ]);
    addAuditLog('Edited Software', "Asset ID: $id — SW ID: $swId");
    $_SESSION['flash_msg']  = 'Software updated.';
    $_SESSION['flash_type'] = 'success';
    header("Location: asset_view.php?id=$id#software"); exit;
}

// ── POST: Delete Software ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_software') {
    $swId = intval($_POST['sw_id']);
    $db->prepare("DELETE FROM installed_software WHERE id=? AND asset_id=?")
       ->execute([$swId, intval($id)]);
    addAuditLog('Removed Software', "Asset ID: $id — SW ID: $swId");
    $_SESSION['flash_msg']  = 'Software removed.';
    $_SESSION['flash_type'] = 'success';
    header("Location: asset_view.php?id=$id#software"); exit;
}

// ── Fetch asset ────────────────────────────────────────────────────────────────
$asset = $db->prepare("
    SELECT a.*, e.name AS emp_name, e.emp_id, e.email, e.phone, e.position,
           d.name AS dept_name, a.building, a.floor, a.room
    FROM assets a
    LEFT JOIN employees e ON a.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE a.id = ?
");
$asset->execute([$id]);
$asset = $asset->fetch();
if (!$asset) { header('Location: inventory.php'); exit; }

$software    = $db->prepare("SELECT * FROM installed_software WHERE asset_id=? ORDER BY name");
$software->execute([$id]);
$software    = $software->fetchAll();

$upgrades    = $db->prepare("SELECT * FROM asset_upgrades WHERE asset_id=? ORDER BY upgrade_date DESC");
$upgrades->execute([$id]);
$upgrades    = $upgrades->fetchAll();

$maintenance = $db->prepare("SELECT * FROM maintenance_tasks WHERE asset_id=? ORDER BY scheduled_date DESC LIMIT 20");
$maintenance->execute([$id]);
$maintenance = $maintenance->fetchAll();

$maintLog    = $db->prepare("SELECT * FROM maintenance_log WHERE asset_id=? ORDER BY log_date DESC LIMIT 20");
$maintLog->execute([$id]);
$maintLog    = $maintLog->fetchAll();

$auditLog    = $db->prepare("SELECT * FROM audit_log WHERE details LIKE ? ORDER BY created_at DESC LIMIT 30");
$auditLog->execute(["%".$asset['asset_tag']."%"]);
$auditLog    = $auditLog->fetchAll();

function statusClass($status) {
    return match($status) {
        'Active'      => 'active',
        'Maintenance' => 'maintenance',
        'Retired'     => 'retired',
        'Lost','Stolen' => 'lost',
        'Disposed'    => 'disposed',
        'Spare'       => 'spare',
        default       => 'active'
    };
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2>
        <i class="fas fa-desktop"></i>
        <?= sanitize($asset['asset_tag']) ?> — <?= sanitize($asset['brand'].' '.$asset['model']) ?>
    </h2>
    <div style="display:flex;gap:9px">
        <?php if (hasRole('Admin','Technician')): ?>
        <a href="inventory_add.php?id=<?= urlencode($id) ?>" class="btn">
            <i class="fas fa-edit"></i> Edit Asset
        </a>
        <?php endif; ?>
        <a href="inventory.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <button class="btn btn-info" onclick="showQR('<?= sanitize($asset['asset_tag']) ?>')">
            <i class="fas fa-qrcode"></i> QR
        </button>
    </div>
</div>

<!-- Flash -->
<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg']  ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<!-- Status Row -->
<div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;flex-wrap:wrap">
    <span class="status-badge status-<?= statusClass($asset['status']) ?>" style="font-size:1rem;padding:8px 18px">
        <?= sanitize($asset['status']) ?>
    </span>
    <span class="badge badge-info"><?= sanitize($asset['device_type']) ?></span>
    <span class="badge badge-warning"><?= sanitize($asset['lifecycle_state'] ?? 'Active') ?></span>
    <?php if ($asset['is_flagged']): ?>
    <span class="badge badge-danger"><i class="fas fa-flag"></i> Flagged</span>
    <?php endif; ?>
    <?php
    $warDays = $asset['warranty_expiry'] ? (strtotime($asset['warranty_expiry']) - time()) / 86400 : 999;
    if ($warDays < 0): ?>
        <span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Warranty Expired</span>
    <?php elseif ($warDays < 30): ?>
        <span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Warranty Expiring Soon</span>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">

<!-- Left Column -->
<div>
    <div class="table-container" style="margin-bottom:22px">
        <h4 style="margin-bottom:16px"><i class="fas fa-user"></i> Assigned Employee</h4>
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Employee ID</span><span><?= sanitize($asset['emp_id'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Name</span><span><?= sanitize($asset['emp_name'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Department</span><span><?= sanitize($asset['dept_name'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Position</span><span><?= sanitize($asset['position'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Email</span><span><?= sanitize($asset['email'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Phone</span><span><?= sanitize($asset['phone'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Location</span>
                <span><?= sanitize(implode(', ', array_filter([$asset['building'], $asset['floor'] ? 'Floor '.$asset['floor'] : '', $asset['room']]))) ?: '—' ?></span>
            </div>
        </div>
    </div>

    <div class="table-container" style="margin-bottom:22px">
        <h4 style="margin-bottom:16px"><i class="fas fa-microchip"></i> Hardware Specifications</h4>
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Asset Tag</span><span><strong><?= sanitize($asset['asset_tag']) ?></strong></span></div>
            <div class="detail-item"><span class="detail-label">Brand</span><span><?= sanitize($asset['brand']) ?></span></div>
            <div class="detail-item"><span class="detail-label">Model</span><span><?= sanitize($asset['model']) ?></span></div>
            <div class="detail-item"><span class="detail-label">Type</span><span><?= sanitize($asset['device_type']) ?></span></div>
            <div class="detail-item"><span class="detail-label">Serial Number</span><span><code><?= sanitize($asset['serial_number']) ?></code></span></div>
            <div class="detail-item"><span class="detail-label">Processor</span><span><?= sanitize($asset['processor'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">RAM</span><span><?= sanitize($asset['ram'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Storage</span><span><?= sanitize($asset['storage'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">GPU</span><span><?= sanitize($asset['gpu'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Monitor</span><span><?= sanitize($asset['monitor'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">OS</span><span><?= sanitize($asset['operating_system'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">OS Version</span><span><?= sanitize($asset['os_version'] ?? '—') ?></span></div>
        </div>
    </div>
</div>

<!-- Right Column -->
<div>
    <div class="table-container" style="margin-bottom:22px">
        <h4 style="margin-bottom:16px"><i class="fas fa-peso-sign"></i> Purchase &amp; Financial</h4>
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Purchase Date</span><span><?= sanitize($asset['purchase_date'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Purchase Cost</span><span><?= peso($asset['purchase_cost'] ?? 0) ?></span></div>
            <div class="detail-item"><span class="detail-label">Supplier</span><span><?= sanitize($asset['supplier'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">PO Number</span><span><?= sanitize($asset['po_number'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Warranty</span><span><?= sanitize($asset['warranty_expiry'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Current Value</span><span><?= peso(calculateDepreciation($asset['purchase_cost'], $asset['purchase_date'], 5)) ?></span></div>
            <div class="detail-item"><span class="detail-label">Lifecycle</span><span><?= sanitize($asset['lifecycle_state'] ?? '—') ?></span></div>
        </div>
    </div>

    <div class="table-container" style="margin-bottom:22px">
        <h4 style="margin-bottom:16px"><i class="fas fa-network-wired"></i> Network</h4>
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">IP Address</span><span><?= sanitize($asset['ip_address'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">MAC Address</span><span><?= sanitize($asset['mac_address'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Connection</span><span><?= sanitize($asset['connection_type'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">ISP</span><span><?= sanitize($asset['isp'] ?? '—') ?></span></div>
        </div>
    </div>

    <div class="table-container" style="margin-bottom:22px">
        <h4 style="margin-bottom:16px"><i class="fas fa-shield-alt"></i> Security</h4>
        <div class="detail-grid">
            <div class="detail-item"><span class="detail-label">Antivirus</span>
                <span class="badge badge-<?= $asset['antivirus_installed'] ? 'success' : 'danger' ?>">
                    <?= $asset['antivirus_installed'] ? 'Installed' : 'Not Installed' ?>
                </span>
            </div>
            <div class="detail-item"><span class="detail-label">Antivirus Name</span><span><?= sanitize($asset['antivirus_name'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Firewall</span>
                <span class="badge badge-<?= $asset['firewall_enabled'] ? 'success' : 'danger' ?>">
                    <?= $asset['firewall_enabled'] ? 'Enabled' : 'Disabled' ?>
                </span>
            </div>
            <div class="detail-item"><span class="detail-label">Encryption</span>
                <span class="badge badge-<?= $asset['encryption_enabled'] ? 'success' : 'warning' ?>">
                    <?= $asset['encryption_enabled'] ? 'Enabled' : 'Disabled' ?>
                </span>
            </div>
            <div class="detail-item"><span class="detail-label">Last Virus Scan</span><span><?= sanitize($asset['last_virus_scan'] ?? '—') ?></span></div>
            <div class="detail-item"><span class="detail-label">Last Backup</span><span><?= sanitize($asset['last_backup'] ?? '—') ?></span></div>
        </div>
    </div>
</div>
</div>

<!-- ── Tabs ───────────────────────────────────────────────────────────────────── -->
<div class="tabs" id="assetTabs">
    <button class="tab-btn active" onclick="switchTab('software',this)">
        <i class="fas fa-code"></i> Software (<?= count($software) ?>)
    </button>
    <button class="tab-btn" onclick="switchTab('upgrades',this)">
        <i class="fas fa-arrow-up"></i> Upgrades (<?= count($upgrades) ?>)
    </button>
    <button class="tab-btn" onclick="switchTab('maintenance',this)">
        <i class="fas fa-tools"></i> Maintenance (<?= count($maintenance) ?>)
    </button>
    <button class="tab-btn" onclick="switchTab('activity',this)">
        <i class="fas fa-history"></i> Activity
    </button>
</div>

<!-- ── SOFTWARE TAB ───────────────────────────────────────────────────────────── -->
<div id="tab-software" class="tab-content active table-container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <h4 style="margin:0"><i class="fas fa-code"></i> Installed Software</h4>
        <?php if (hasRole('Admin','Technician')): ?>
        <button class="btn btn-success btn-sm" id="btnAddSoftware">
            <i class="fas fa-plus"></i> Add Software
        </button>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Version</th>
                <th>License Key</th>
                <th>Install Date</th>
                <?php if (hasRole('Admin','Technician')): ?><th>Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($software as $sw): ?>
        <tr>
            <td><strong><?= sanitize($sw['name']) ?></strong></td>
            <td><?= sanitize($sw['version'] ?? '—') ?></td>
            <td><code><?= sanitize($sw['license_key'] ?? '—') ?></code></td>
            <td><?= sanitize($sw['install_date'] ?? '—') ?></td>
            <?php if (hasRole('Admin','Technician')): ?>
            <td style="white-space:nowrap">
                <button class="action-btn btn-edit-sw" title="Edit"
                    style="color:#007bff"
                    data-id="<?= (int)$sw['id'] ?>"
                    data-name="<?= htmlspecialchars($sw['name'],         ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-version="<?= htmlspecialchars($sw['version'] ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-key="<?= htmlspecialchars($sw['license_key'] ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-date="<?= htmlspecialchars($sw['install_date'] ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="action-btn btn-del-sw" title="Delete"
                    style="color:#dc3545"
                    data-id="<?= (int)$sw['id'] ?>"
                    data-name="<?= htmlspecialchars($sw['name'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($software)): ?>
        <tr><td colspan="<?= hasRole('Admin','Technician') ? 5 : 4 ?>">
            <div class="empty-state">
                <i class="fas fa-code"></i>
                <p>No software records. <?php if (hasRole('Admin','Technician')): ?><a href="#" id="linkAddSw">Add one?</a><?php endif; ?></p>
            </div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ── UPGRADES TAB ───────────────────────────────────────────────────────────── -->
<div id="tab-upgrades" class="tab-content table-container" style="display:none">
    <table>
        <thead><tr><th>Date</th><th>Type</th><th>From</th><th>To</th><th>Cost</th><th>Technician</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($upgrades as $u): ?>
        <tr>
            <td><?= sanitize($u['upgrade_date'] ?? '—') ?></td>
            <td><?= sanitize($u['upgrade_type']) ?></td>
            <td><?= sanitize($u['old_value'] ?? '—') ?></td>
            <td><?= sanitize($u['new_value']) ?></td>
            <td><?= peso($u['cost'] ?? 0) ?></td>
            <td><?= sanitize($u['technician'] ?? '—') ?></td>
            <td><?= sanitize($u['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($upgrades)): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-arrow-up"></i><p>No upgrade records.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ── MAINTENANCE TAB ────────────────────────────────────────────────────────── -->
<div id="tab-maintenance" class="tab-content table-container" style="display:none">
    <h5 style="margin-bottom:12px">Scheduled Tasks</h5>
    <table>
        <thead><tr><th>Task</th><th>Scheduled</th><th>Priority</th><th>Assigned To</th><th>Status</th><th>Cost</th></tr></thead>
        <tbody>
        <?php foreach ($maintenance as $m): ?>
        <tr>
            <td><?= sanitize($m['task_name']) ?></td>
            <td><?= sanitize($m['scheduled_date'] ?? '—') ?></td>
            <td><span class="badge badge-<?= in_array($m['priority'],['High','Critical'])?'danger':($m['priority']==='Medium'?'warning':'info') ?>"><?= sanitize($m['priority']) ?></span></td>
            <td><?= sanitize($m['assigned_to'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $m['status']==='Completed'?'success':($m['status']==='In Progress'?'warning':'info') ?>"><?= sanitize($m['status']) ?></span></td>
            <td><?= peso($m['cost'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($maintenance)): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-tools"></i><p>No maintenance tasks.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php if ($maintLog): ?>
    <h5 style="margin:20px 0 12px">Maintenance Log</h5>
    <table>
        <thead><tr><th>Date</th><th>Type</th><th>Issue</th><th>Technician</th><th>Cost</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($maintLog as $ml): ?>
        <tr>
            <td><?= sanitize($ml['log_date'] ?? '—') ?></td>
            <td><?= sanitize($ml['type'] ?? '—') ?></td>
            <td><?= sanitize($ml['issue'] ?? '—') ?></td>
            <td><?= sanitize($ml['technician'] ?? '—') ?></td>
            <td><?= peso($ml['cost'] ?? 0) ?></td>
            <td><span class="badge badge-<?= $ml['status']==='Completed'?'success':'warning' ?>"><?= sanitize($ml['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ── ACTIVITY TAB ───────────────────────────────────────────────────────────── -->
<div id="tab-activity" class="tab-content table-container" style="display:none">
    <table>
        <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($auditLog as $al): ?>
        <tr>
            <td><?= date('M d, Y H:i', strtotime($al['created_at'])) ?></td>
            <td><?= sanitize($al['user_name']) ?></td>
            <td><?= sanitize($al['action']) ?></td>
            <td><?= sanitize($al['details'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($auditLog)): ?>
        <tr><td colspan="4"><div class="empty-state"><i class="fas fa-history"></i><p>No activity logged.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- ════════════ MODAL STYLES ════════════ -->
<style>
.detail-grid  { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.detail-item  { display:flex; flex-direction:column; gap:3px; padding:8px; background:var(--bg-tertiary); border-radius:6px; }
.detail-label { font-size:.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
.tabs     { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
.tab-btn  { padding:8px 18px; background:var(--bg-secondary); border:none; border-radius:6px; color:var(--text-secondary); cursor:pointer; font-size:.9rem; }
.tab-btn.active { background:var(--primary); color:#fff; }

.av-overlay {
    position:fixed;inset:0;background:rgba(0,0,0,.55);
    z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;
}
.av-modal {
    background:#fff;border-radius:12px;padding:28px;
    width:100%;max-width:460px;max-height:90vh;overflow-y:auto;
    position:relative;box-shadow:0 12px 48px rgba(0,0,0,.25);
    animation:avSlide .18s ease;
}
@keyframes avSlide{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}
.av-modal h3{margin:0 0 20px;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.av-mclose{position:absolute;top:13px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:#aaa;padding:4px 8px;border-radius:5px;line-height:1}
.av-mclose:hover{background:#f0f0f0;color:#333}
.av-fg{margin-bottom:13px}
.av-fg label{display:block;font-size:12px;font-weight:700;margin-bottom:4px;color:#555;text-transform:uppercase;letter-spacing:.3px}
.av-fg input,.av-fg select{display:block;width:100%;padding:9px 11px;border:1.5px solid #dde1e7;border-radius:7px;font-size:13px;color:#333;background:#fff;font-family:inherit;transition:border-color .15s,box-shadow .15s}
.av-fg input:focus,.av-fg select:focus{outline:none;border-color:#007bff;box-shadow:0 0 0 3px rgba(0,123,255,.12)}
.av-frow{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.av-mfoot{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid #f0f0f0}
.av-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:filter .15s}
.av-btn:hover{filter:brightness(.91)}
.av-btn-green{background:#28a745;color:#fff}
.av-btn-blue{background:#007bff;color:#fff}
.av-btn-red{background:#dc3545;color:#fff}
.av-btn-gray{background:#6c757d;color:#fff}
</style>


<!-- MODAL: Add Software -->
<div class="av-overlay" id="av_mAddSw" style="display:none">
  <div class="av-modal">
    <button type="button" class="av-mclose js-av-close" data-target="av_mAddSw">&times;</button>
    <h3><i class="fas fa-plus-circle" style="color:#28a745"></i> Add Software</h3>
    <form method="POST" action="asset_view.php?id=<?= (int)$id ?>" autocomplete="off">
      <input type="hidden" name="action" value="add_software">
      <div class="av-fg">
        <label>Software Name <span style="color:red">*</span></label>
        <input type="text" name="name" required placeholder="e.g. Microsoft Office 365">
      </div>
      <div class="av-frow">
        <div class="av-fg">
          <label>Version</label>
          <input type="text" name="version" placeholder="e.g. 16.0">
        </div>
        <div class="av-fg">
          <label>Install Date</label>
          <input type="date" name="install_date" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="av-fg">
        <label>License Key</label>
        <input type="text" name="license_key" placeholder="XXXXX-XXXXX-XXXXX">
      </div>
      <div class="av-mfoot">
        <button type="button" class="av-btn av-btn-gray js-av-close" data-target="av_mAddSw">Cancel</button>
        <button type="submit" class="av-btn av-btn-green"><i class="fas fa-save"></i> Add Software</button>
      </div>
    </form>
  </div>
</div>


<!-- MODAL: Edit Software -->
<div class="av-overlay" id="av_mEditSw" style="display:none">
  <div class="av-modal">
    <button type="button" class="av-mclose js-av-close" data-target="av_mEditSw">&times;</button>
    <h3><i class="fas fa-edit" style="color:#007bff"></i> Edit Software</h3>
    <form method="POST" action="asset_view.php?id=<?= (int)$id ?>" autocomplete="off">
      <input type="hidden" name="action" value="edit_software">
      <input type="hidden" name="sw_id" id="edit_sw_id">
      <div class="av-fg">
        <label>Software Name <span style="color:red">*</span></label>
        <input type="text" name="name" id="edit_sw_name" required>
      </div>
      <div class="av-frow">
        <div class="av-fg">
          <label>Version</label>
          <input type="text" name="version" id="edit_sw_version">
        </div>
        <div class="av-fg">
          <label>Install Date</label>
          <input type="date" name="install_date" id="edit_sw_date">
        </div>
      </div>
      <div class="av-fg">
        <label>License Key</label>
        <input type="text" name="license_key" id="edit_sw_key">
      </div>
      <div class="av-mfoot">
        <button type="button" class="av-btn av-btn-gray js-av-close" data-target="av_mEditSw">Cancel</button>
        <button type="submit" class="av-btn av-btn-blue"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>


<!-- MODAL: Confirm Delete Software -->
<div class="av-overlay" id="av_mDelSw" style="display:none">
  <div class="av-modal" style="max-width:400px">
    <button type="button" class="av-mclose js-av-close" data-target="av_mDelSw">&times;</button>
    <h3><i class="fas fa-exclamation-triangle" style="color:#dc3545"></i> Remove Software</h3>
    <p style="font-size:14px;color:#444;margin-bottom:4px">
      Remove <strong id="del_sw_name"></strong> from this asset? This cannot be undone.
    </p>
    <form method="POST" action="asset_view.php?id=<?= (int)$id ?>">
      <input type="hidden" name="action" value="delete_software">
      <input type="hidden" name="sw_id" id="del_sw_id">
      <div class="av-mfoot">
        <button type="button" class="av-btn av-btn-gray js-av-close" data-target="av_mDelSw">Cancel</button>
        <button type="submit" class="av-btn av-btn-red"><i class="fas fa-trash"></i> Yes, Remove</button>
      </div>
    </form>
  </div>
</div>


<script>
function switchTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(function(t){ t.style.display='none'; });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-'+name).style.display = '';
    if (btn) btn.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function () {
    function g(id){ return document.getElementById(id); }
    function openM(id){ var el=g(id); if(el) el.style.display='flex'; }
    function closeM(id){ var el=g(id); if(el) el.style.display='none'; }

    /* backdrop + ESC */
    document.addEventListener('click', function(e){
        if(e.target && e.target.classList.contains('av-overlay')) e.target.style.display='none';
    });
    document.addEventListener('keydown', function(e){
        if(e.key==='Escape') document.querySelectorAll('.av-overlay').forEach(function(o){ o.style.display='none'; });
    });

    /* close buttons */
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.js-av-close');
        if(btn) closeM(btn.getAttribute('data-target'));
    });

    /* Add Software button */
    var btnAdd = g('btnAddSoftware');
    if(btnAdd) btnAdd.addEventListener('click', function(){ openM('av_mAddSw'); });

    /* "Add one?" link in empty state */
    var linkAdd = g('linkAddSw');
    if(linkAdd) linkAdd.addEventListener('click', function(e){ e.preventDefault(); openM('av_mAddSw'); });

    /* Edit Software */
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.btn-edit-sw');
        if(!btn) return;
        g('edit_sw_id').value      = btn.getAttribute('data-id');
        g('edit_sw_name').value    = btn.getAttribute('data-name');
        g('edit_sw_version').value = btn.getAttribute('data-version');
        g('edit_sw_key').value     = btn.getAttribute('data-key');
        g('edit_sw_date').value    = btn.getAttribute('data-date');
        openM('av_mEditSw');
    });

    /* Delete Software */
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.btn-del-sw');
        if(!btn) return;
        g('del_sw_name').textContent = btn.getAttribute('data-name');
        g('del_sw_id').value         = btn.getAttribute('data-id');
        openM('av_mDelSw');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>