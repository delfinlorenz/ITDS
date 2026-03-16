<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Extra Supplies';
$currentPage = 'supplies';
$db = db();

// Handle POST actions
if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $newId = generateId('SUP', $db->query("SELECT COUNT(*)+1 FROM supplies")->fetchColumn());
        $db->prepare("INSERT INTO supplies (id, type, brand, model, serial_number, `condition`, location, purchase_date, purchase_cost, warranty_expiry, notes, status)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,'Available')")
           ->execute([
               $newId,
               trim($_POST['type']),   trim($_POST['brand']),  trim($_POST['model']),
               trim($_POST['serial_number']), trim($_POST['condition']),
               trim($_POST['location']), $_POST['purchase_date'] ?: null,
               floatval($_POST['purchase_cost'] ?? 0),
               $_POST['warranty_expiry'] ?: null,
               trim($_POST['notes'] ?? '')
           ]);
        addAuditLog('Added Supply', $newId.' - '.trim($_POST['type']));
        $_SESSION['flash_msg']  = "Supply $newId added.";
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'edit') {
        $sid = trim($_POST['id']);
        $db->prepare("UPDATE supplies SET type=?,brand=?,model=?,serial_number=?,status=?,`condition`=?,location=?,purchase_date=?,purchase_cost=?,warranty_expiry=?,notes=? WHERE id=?")
           ->execute([
               trim($_POST['type']),  trim($_POST['brand']),  trim($_POST['model']),
               trim($_POST['serial_number']), trim($_POST['status']),
               trim($_POST['condition']), trim($_POST['location']),
               $_POST['purchase_date'] ?: null,
               floatval($_POST['purchase_cost'] ?? 0),
               $_POST['warranty_expiry'] ?: null,
               trim($_POST['notes'] ?? ''), $sid
           ]);
        addAuditLog('Updated Supply', $sid);
        $_SESSION['flash_msg']  = "Supply $sid updated.";
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'delete') {
        $sid = trim($_POST['id']);
        $db->prepare("DELETE FROM supplies WHERE id=?")->execute([$sid]);
        addAuditLog('Deleted Supply', $sid);
        $_SESSION['flash_msg']  = "Supply $sid deleted.";
        $_SESSION['flash_type'] = 'danger';
    }

    if ($action === 'assign') {
        $sid     = trim($_POST['supply_id']);
        $empName = trim($_POST['emp_name']);
        $date    = $_POST['assigned_date'] ?: date('Y-m-d');
        $reason  = trim($_POST['reason'] ?? 'New Assignment');
        $notes   = trim($_POST['notes'] ?? '');
        $replFor = trim($_POST['replacement_for'] ?? '');

        $db->prepare("UPDATE supplies SET status='In Use', assigned_to=?, assigned_date=?, replacement_for=? WHERE id=?")
           ->execute([$empName, $date, $replFor, $sid]);

        // Supply history
        $db->prepare("INSERT INTO supply_history (supply_id, action_date, action, details) VALUES (?,?,?,?)")
           ->execute([$sid, $date, "Assigned to $empName", "Reason: $reason. $notes"]);

        // Record transaction
        $supply = $db->prepare("SELECT * FROM supplies WHERE id=?");
        $supply->execute([$sid]);
        $supply = $supply->fetch();
        $txId   = generateId('TR', $db->query("SELECT COUNT(*)+1 FROM supply_transactions")->fetchColumn());
        $assetTag = trim($_POST['asset_tag'] ?? '—');
        $db->prepare("INSERT INTO supply_transactions (id, supply_id, supply_type, supply_brand, supply_model, asset_tag, emp_name, old_item, new_item, reason, transaction_date, technician, notes)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $txId, $sid,
               $supply['type'] ?? '', $supply['brand'] ?? '', $supply['model'] ?? '',
               $assetTag, $empName,
               $replFor, ($supply['type'].' - '.$supply['brand'].' '.$supply['model']),
               $reason, $date, $empName, $notes
           ]);

        addAuditLog('Assigned Supply', "$sid to $empName");
        $_SESSION['flash_msg']  = "Supply assigned to $empName.";
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: supplies.php');
    exit;
}

// Filters
$typeFilter   = $_GET['type']   ?? '';
$statusFilter = $_GET['status'] ?? '';
$search       = $_GET['search'] ?? '';

$where = []; $params = [];
if ($typeFilter)   { $where[] = "type=?";   $params[] = $typeFilter; }
if ($statusFilter) { $where[] = "status=?"; $params[] = $statusFilter; }
if ($search) {
    $like = "%$search%";
    $where[] = "(id LIKE ? OR brand LIKE ? OR model LIKE ? OR serial_number LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

$supplies = $db->prepare("SELECT * FROM supplies $whereSQL ORDER BY id ASC LIMIT 300");
$supplies->execute($params);
$supplies = $supplies->fetchAll();

// Stats
$available = $db->query("SELECT COUNT(*) FROM supplies WHERE status='Available'")->fetchColumn();
$inUse     = $db->query("SELECT COUNT(*) FROM supplies WHERE status='In Use'")->fetchColumn();
$defective = $db->query("SELECT COUNT(*) FROM supplies WHERE status='Defective'")->fetchColumn();
$totalVal  = $db->query("SELECT COALESCE(SUM(purchase_cost),0) FROM supplies")->fetchColumn();

// Transactions
$transactions = $db->query("SELECT * FROM supply_transactions ORDER BY created_at DESC LIMIT 30")->fetchAll();

// Assets for assignment dropdown
$assets = $db->query("SELECT a.id, a.asset_tag, e.name AS emp_name, d.name AS dept
    FROM assets a LEFT JOIN employees e ON a.employee_id=e.id LEFT JOIN departments d ON e.department_id=d.id
    ORDER BY a.asset_tag LIMIT 300")->fetchAll();

$types      = ['Monitor','Keyboard','Mouse','RAM','HDD','SSD','Power Supply','Cable','Headset','Webcam','Other'];
$conditions = ['New','Like New','Good','Fair','Poor'];
$locations  = ['Main Building','IT Tower','Annex','Remote Office','Data Center'];
$statuses   = ['Available','In Use','Defective','Disposed'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-boxes"></i> Extra Supplies</h2>
    <div style="display:flex;gap:9px">
        <?php if (hasRole('Admin','Technician')): ?>
        <button class="btn btn-success" onclick="document.getElementById('addSupplyModal').classList.add('show')">
            <i class="fas fa-plus"></i> Add Supply
        </button>
        <button class="btn btn-info" onclick="document.getElementById('recordReplacementModal').classList.add('show')">
            <i class="fas fa-exchange-alt"></i> Record Replacement
        </button>
        <?php endif; ?>
        <button class="btn btn-secondary" onclick="exportTableToExcel('suppliesTable','supplies_export')">
            <i class="fas fa-file-excel"></i> Export
        </button>
    </div>
</div>

<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg'] ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<!-- Stats -->
<div class="stat-grid">
    <a class="stat-card" href="supplies.php?status=Available"><div class="stat-title">Available</div><div class="stat-value"><?= $available ?></div></a>
    <a class="stat-card" href="supplies.php?status=In+Use"><div class="stat-title">In Use</div><div class="stat-value"><?= $inUse ?></div></a>
    <a class="stat-card" href="supplies.php?status=Defective"><div class="stat-title">Defective</div><div class="stat-value"><?= $defective ?></div></a>
    <div class="stat-card"><div class="stat-title">Total Value</div><div class="stat-value" style="font-size:1.3rem"><?= peso($totalVal) ?></div></div>
</div>

<!-- Type quick filters -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
    <?php foreach (['Monitor','RAM','SSD','HDD','Keyboard','Mouse','Cable'] as $qt): ?>
    <a href="supplies.php?type=<?= urlencode($qt) ?>"
       class="badge badge-<?= $typeFilter===$qt?'primary':'info' ?>"
       style="padding:6px 14px;font-size:.85rem;cursor:pointer;text-decoration:none"><?= $qt ?></a>
    <?php endforeach; ?>
    <?php if ($typeFilter): ?><a href="supplies.php" class="badge badge-danger" style="padding:6px 14px;font-size:.85rem;text-decoration:none">✕ Clear</a><?php endif; ?>
</div>

<!-- Filters -->
<form method="GET">
<div class="filter-bar">
    <input class="filter-select" name="search" placeholder="Search ID, brand, model, serial..." value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="type">
        <option value="">All Types</option>
        <?php foreach ($types as $t): ?>
        <option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>
    <select class="filter-select" name="status">
        <option value="">All Status</option>
        <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="supplies.php" class="btn btn-secondary">Clear</a>
</div>
</form>

<!-- Supplies Table -->
<div class="table-container">
    <div class="table-header">
        <h4>Supply Inventory (<?= count($supplies) ?> items)</h4>
    </div>
    <table id="suppliesTable">
        <thead>
            <tr>
                <th>ID</th><th>Type</th><th>Brand / Model</th><th>Serial</th>
                <th>Status</th><th>Condition</th><th>Location</th><th>Assigned To</th><th>Value</th><th>Warranty</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($supplies as $s): ?>
        <tr>
            <td><strong><?= sanitize($s['id']) ?></strong></td>
            <td><?= sanitize($s['type']) ?></td>
            <td><?= sanitize($s['brand'].' '.$s['model']) ?></td>
            <td><code><?= sanitize($s['serial_number']) ?></code></td>
            <td>
                <span class="status-badge status-<?= $s['status']==='Available'?'active':($s['status']==='In Use'?'maintenance':($s['status']==='Defective'?'lost':'retired')) ?>">
                    <?= sanitize($s['status']) ?>
                </span>
            </td>
            <td><?= sanitize($s['condition']) ?></td>
            <td><?= sanitize($s['location']) ?></td>
            <td><?= sanitize($s['assigned_to'] ?? '—') ?></td>
            <td><?= peso($s['purchase_cost'] ?? 0) ?></td>
            <td>
                <?php if ($s['warranty_expiry']): ?>
                    <?php $wd = (strtotime($s['warranty_expiry']) - time()) / 86400; ?>
                    <?= sanitize($s['warranty_expiry']) ?>
                    <?php if ($wd < 0): ?><br><small style="color:var(--danger)">Expired</small>
                    <?php elseif ($wd < 30): ?><br><small style="color:var(--warning)">Soon</small>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <?php if (hasRole('Admin','Technician') && $s['status'] === 'Available'): ?>
                <button class="action-btn" title="Assign"
                        onclick="openAssignModal('<?= sanitize($s['id']) ?>')"
                        style="color:var(--success)">
                    <i class="fas fa-user-check"></i>
                </button>
                <?php endif; ?>
                <?php if (hasRole('Admin','Technician')): ?>
                <button class="action-btn" title="Edit"
                        onclick="openEditSupplyModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)">
                    <i class="fas fa-edit"></i>
                </button>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Delete supply <?= sanitize($s['id']) ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= sanitize($s['id']) ?>">
                    <button type="submit" class="action-btn" style="color:var(--danger)" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($supplies)): ?>
        <tr><td colspan="11"><div class="empty-state"><i class="fas fa-boxes"></i><p>No supplies found.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Replacement History -->
<div class="table-container">
    <h4 style="margin-bottom:16px">Replacement History (Last 30)</h4>
    <table>
        <thead>
            <tr><th>Date</th><th>Supply</th><th>Asset</th><th>Employee</th><th>Reason</th><th>Old Item</th><th>Technician</th><th>Notes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $tx): ?>
        <tr>
            <td><?= sanitize($tx['transaction_date'] ?? '') ?></td>
            <td><?= sanitize($tx['supply_id']) ?> — <?= sanitize($tx['supply_type'].' '.$tx['supply_brand']) ?></td>
            <td><?= sanitize($tx['asset_tag'] ?? '—') ?></td>
            <td><?= sanitize($tx['emp_name']) ?></td>
            <td><span class="badge badge-<?= $tx['reason']==='Defective'?'danger':($tx['reason']==='Upgrade'?'success':'warning') ?>"><?= sanitize($tx['reason']) ?></span></td>
            <td><?= sanitize($tx['old_item'] ?? '—') ?></td>
            <td><?= sanitize($tx['technician'] ?? '—') ?></td>
            <td><?= sanitize($tx['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($transactions)): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-exchange-alt"></i><p>No replacement records yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ===== ADD SUPPLY MODAL ===== -->
<div class="modal" id="addSupplyModal">
    <div class="modal-content" style="max-width:680px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-plus"></i> Add New Supply</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-group">
                    <label>Type *</label>
                    <select name="type" required>
                        <?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Brand *</label><input name="brand" required></div>
                <div class="form-group"><label>Model *</label><input name="model" required></div>
                <div class="form-group"><label>Serial Number *</label><input name="serial_number" required></div>
                <div class="form-group">
                    <label>Condition</label>
                    <select name="condition">
                        <?php foreach ($conditions as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <select name="location">
                        <?php foreach ($locations as $l): ?><option value="<?= $l ?>"><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Purchase Date</label><input type="date" name="purchase_date"></div>
                <div class="form-group"><label>Purchase Cost (₱)</label><input type="number" name="purchase_cost" step="0.01" placeholder="0.00"></div>
                <div class="form-group"><label>Warranty Expiry</label><input type="date" name="warranty_expiry"></div>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Supply</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== EDIT SUPPLY MODAL ===== -->
<div class="modal" id="editSupplyModal">
    <div class="modal-content" style="max-width:680px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-edit"></i> Edit Supply</h2>
        <form method="POST" id="editSupplyForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editSupplyId">
            <div class="form-grid">
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" id="editType">
                        <?php foreach ($types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Brand</label><input name="brand" id="editBrand"></div>
                <div class="form-group"><label>Model</label><input name="model" id="editModel"></div>
                <div class="form-group"><label>Serial Number</label><input name="serial_number" id="editSerial"></div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="editStatus">
                        <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Condition</label>
                    <select name="condition" id="editCondition">
                        <?php foreach ($conditions as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <select name="location" id="editLocation">
                        <?php foreach ($locations as $l): ?><option value="<?= $l ?>"><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Purchase Date</label><input type="date" name="purchase_date" id="editPurchaseDate"></div>
                <div class="form-group"><label>Purchase Cost (₱)</label><input type="number" name="purchase_cost" id="editCost" step="0.01"></div>
                <div class="form-group"><label>Warranty Expiry</label><input type="date" name="warranty_expiry" id="editWarranty"></div>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="notes" id="editNotes" rows="2"></textarea></div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Supply</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== ASSIGN SUPPLY MODAL ===== -->
<div class="modal" id="assignSupplyModal">
    <div class="modal-content" style="max-width:540px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-user-check"></i> Assign Supply</h2>
        <form method="POST">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="supply_id" id="assignSupplyId">
            <div class="form-group">
                <label>Assign to Asset / Employee *</label>
                <select name="asset_tag" id="assignAssetTag" onchange="fillEmpName(this)" required>
                    <option value="">Select Asset</option>
                    <?php foreach ($assets as $a): ?>
                    <option value="<?= sanitize($a['asset_tag']) ?>" data-emp="<?= sanitize($a['emp_name'] ?? '') ?>">
                        <?= sanitize($a['asset_tag']) ?> — <?= sanitize($a['emp_name'] ?? '—') ?> (<?= sanitize($a['dept'] ?? '') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="emp_name" id="assignEmpName">
            <div class="form-row">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="assigned_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <select name="reason">
                        <option value="New Assignment">New Assignment</option>
                        <option value="Replacement">Replacement</option>
                        <option value="Upgrade">Upgrade</option>
                        <option value="Temporary">Temporary</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Replacing Item (if any)</label><input name="replacement_for" placeholder="e.g. Old Monitor - Dell 24&quot;"></div>
            <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Assign</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== RECORD REPLACEMENT MODAL ===== -->
<div class="modal" id="recordReplacementModal">
    <div class="modal-content" style="max-width:600px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-exchange-alt"></i> Record Replacement</h2>
        <form method="POST">
            <input type="hidden" name="action" value="assign">
            <div class="form-group">
                <label>New Supply (Available only) *</label>
                <select name="supply_id" required>
                    <option value="">Select Supply</option>
                    <?php foreach ($supplies as $s): if ($s['status'] !== 'Available') continue; ?>
                    <option value="<?= sanitize($s['id']) ?>">
                        <?= sanitize($s['id']) ?> — <?= sanitize($s['type'].' '.$s['brand'].' '.$s['model']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Asset / Employee *</label>
                <select name="asset_tag" onchange="fillEmpName2(this)" required>
                    <option value="">Select Asset</option>
                    <?php foreach ($assets as $a): ?>
                    <option value="<?= sanitize($a['asset_tag']) ?>" data-emp="<?= sanitize($a['emp_name'] ?? '') ?>">
                        <?= sanitize($a['asset_tag']) ?> — <?= sanitize($a['emp_name'] ?? '—') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="emp_name" id="replEmpName">
            <div class="form-group"><label>Item Being Replaced *</label><input name="replacement_for" required placeholder="e.g. Monitor - HP 22&quot; SN12345"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Reason</label>
                    <select name="reason">
                        <option value="Defective">Defective</option>
                        <option value="Damaged">Damaged</option>
                        <option value="Upgrade">Upgrade</option>
                        <option value="Lost">Lost</option>
                    </select>
                </div>
                <div class="form-group"><label>Date</label><input type="date" name="assigned_date" value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Record Replacement</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// Close modals on backdrop click
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
});

function openAssignModal(supplyId) {
    document.getElementById('assignSupplyId').value = supplyId;
    document.getElementById('assignSupplyModal').classList.add('show');
}

function openEditSupplyModal(supply) {
    document.getElementById('editSupplyId').value         = supply.id;
    document.getElementById('editType').value             = supply.type;
    document.getElementById('editBrand').value            = supply.brand;
    document.getElementById('editModel').value            = supply.model;
    document.getElementById('editSerial').value           = supply.serial_number;
    document.getElementById('editStatus').value           = supply.status;
    document.getElementById('editCondition').value        = supply.condition;
    document.getElementById('editLocation').value         = supply.location;
    document.getElementById('editPurchaseDate').value     = supply.purchase_date || '';
    document.getElementById('editCost').value             = supply.purchase_cost || '';
    document.getElementById('editWarranty').value         = supply.warranty_expiry || '';
    document.getElementById('editNotes').value            = supply.notes || '';
    document.getElementById('editSupplyModal').classList.add('show');
}

function fillEmpName(sel) {
    document.getElementById('assignEmpName').value = sel.options[sel.selectedIndex].dataset.emp || '';
}
function fillEmpName2(sel) {
    document.getElementById('replEmpName').value = sel.options[sel.selectedIndex].dataset.emp || '';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
