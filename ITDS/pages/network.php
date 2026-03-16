<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Technician','Manager');

$pageTitle   = 'Network Devices';
$currentPage = 'network';
$db = db();

if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $db->prepare("INSERT INTO network_devices (hostname, ip_address, mac_address, device_type, manufacturer, location, status, notes)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([
               trim($_POST['hostname']), trim($_POST['ip_address']), trim($_POST['mac_address'] ?? ''),
               trim($_POST['device_type']), trim($_POST['manufacturer'] ?? ''), trim($_POST['location'] ?? ''),
               $_POST['status'] ?? 'Online', trim($_POST['notes'] ?? '')
           ]);
        addAuditLog('Added Network Device', trim($_POST['ip_address']));
        $_SESSION['flash_msg']  = 'Network device added.';
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM network_devices WHERE id=?")->execute([intval($_POST['id'])]);
        addAuditLog('Deleted Network Device', 'ID '.$_POST['id']);
        $_SESSION['flash_msg']  = 'Device removed.';
        $_SESSION['flash_type'] = 'danger';
    }

    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $newStatus = trim($_POST['new_status'] ?? 'Online');
        $db->prepare("UPDATE network_devices SET status=?, last_seen=NOW() WHERE id=?")->execute([$newStatus, $id]);
        addAuditLog('Updated Network Device Status', "ID $id -> $newStatus");
        $_SESSION['flash_msg']  = "Device status updated to $newStatus.";
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: network.php');
    exit;
}

$devices = $db->query("SELECT * FROM network_devices ORDER BY status ASC, ip_address ASC LIMIT 200")->fetchAll();

$online  = count(array_filter($devices, fn($d) => $d['status'] === 'Online'));
$offline = count(array_filter($devices, fn($d) => $d['status'] === 'Offline'));
$unknown = count(array_filter($devices, fn($d) => $d['status'] === 'Unknown'));

$deviceTypes = ['Router','Switch','Access Point','Server','Printer','Firewall','NAS','IP Camera','VoIP Phone','Other'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-network-wired"></i> Network Devices</h2>
    <?php if (hasRole('Admin','Technician')): ?>
    <button class="btn btn-success" onclick="document.getElementById('addNetModal').classList.add('show')">
        <i class="fas fa-plus"></i> Add Device
    </button>
    <?php endif; ?>
</div>

<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg'] ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-title">Total Devices</div><div class="stat-value"><?= count($devices) ?></div></div>
    <div class="stat-card"><div class="stat-title" style="color:var(--success)">Online</div><div class="stat-value"><?= $online ?></div></div>
    <div class="stat-card"><div class="stat-title" style="color:var(--danger)">Offline</div><div class="stat-value"><?= $offline ?></div></div>
    <div class="stat-card"><div class="stat-title" style="color:var(--text-muted)">Unknown</div><div class="stat-value"><?= $unknown ?></div></div>
</div>

<div class="table-container">
    <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:14px">
        <i class="fas fa-info-circle"></i> Status is manually managed. Use the ping tool in Inventory for live checks.
    </p>
    <table>
        <thead>
            <tr><th>Hostname</th><th>IP Address</th><th>MAC Address</th><th>Type</th><th>Manufacturer</th><th>Location</th><th>Status</th><th>Last Seen</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
        <tr>
            <td><strong><?= sanitize($d['hostname'] ?? '—') ?></strong></td>
            <td><code><?= sanitize($d['ip_address']) ?></code></td>
            <td><small><?= sanitize($d['mac_address'] ?? '—') ?></small></td>
            <td><?= sanitize($d['device_type'] ?? '—') ?></td>
            <td><?= sanitize($d['manufacturer'] ?? '—') ?></td>
            <td><?= sanitize($d['location'] ?? '—') ?></td>
            <td>
                <span class="status-badge status-<?= $d['status']==='Online'?'active':($d['status']==='Offline'?'lost':'spare') ?>">
                    <?= sanitize($d['status']) ?>
                </span>
            </td>
            <td><?= $d['last_seen'] ? date('M d H:i', strtotime($d['last_seen'])) : '—' ?></td>
            <td>
                <?php if (hasRole('Admin','Technician')): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <input type="hidden" name="new_status" value="<?= $d['status']==='Online'?'Offline':'Online' ?>">
                    <button type="submit" class="action-btn" title="Toggle Status"
                            style="color:<?= $d['status']==='Online'?'var(--danger)':'var(--success)' ?>">
                        <i class="fas fa-<?= $d['status']==='Online'?'toggle-on':'toggle-off' ?>"></i>
                    </button>
                </form>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Remove device <?= sanitize($d['hostname']) ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <button type="submit" class="action-btn" style="color:var(--danger)" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($devices)): ?>
        <tr><td colspan="9"><div class="empty-state"><i class="fas fa-network-wired"></i><p>No network devices recorded.<br>Add switches, routers, printers, and other infrastructure here.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Device Modal -->
<div class="modal" id="addNetModal">
    <div class="modal-content" style="max-width:560px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-plus"></i> Add Network Device</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group"><label>Hostname</label><input name="hostname" placeholder="e.g. SWITCH-01"></div>
            <div class="form-group"><label>IP Address *</label><input name="ip_address" required placeholder="e.g. 192.168.1.1"></div>
            <div class="form-group"><label>MAC Address</label><input name="mac_address" placeholder="e.g. AA:BB:CC:DD:EE:FF"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Device Type *</label>
                    <select name="device_type" required>
                        <?php foreach ($deviceTypes as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="Online">Online</option>
                        <option value="Offline">Offline</option>
                        <option value="Unknown">Unknown</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Manufacturer</label><input name="manufacturer" placeholder="e.g. Cisco, TP-Link"></div>
            <div class="form-group"><label>Location</label><input name="location" placeholder="e.g. Server Room, Floor 3"></div>
            <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Add Device</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); }));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
