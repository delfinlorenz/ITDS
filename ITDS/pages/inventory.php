<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Inventory';
$currentPage = 'inventory';
$db = db();

// ── POST: Add Spare ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_spare') {
    $db->prepare("
        INSERT INTO assets (asset_tag, brand, model, serial_number, processor, ram,
                            storage, purchase_cost, purchase_date, warranty_expiry,
                            status, lifecycle_state)
        VALUES (?,?,?,?,?,?,?,?,?,?,'Spare','Purchased')
    ")->execute([
        trim($_POST['asset_tag']),
        trim($_POST['brand']),
        trim($_POST['model']),
        trim($_POST['serial_number'] ?? ''),
        trim($_POST['processor']     ?? ''),
        trim($_POST['ram']           ?? ''),
        trim($_POST['storage']       ?? ''),
        floatval($_POST['purchase_cost'] ?? 0),
        !empty($_POST['purchase_date'])   ? $_POST['purchase_date']   : null,
        !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null,
    ]);
    addAuditLog('Added Spare Computer', trim($_POST['asset_tag']));
    $_SESSION['flash_msg']  = 'Spare computer added successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: inventory.php');
    exit;
}

// ── POST: Assign Spare ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_spare') {
    $assetId    = intval($_POST['asset_id']);
    $employeeId = intval($_POST['employee_id']);

    $db->prepare("
        UPDATE assets SET employee_id=?, status='Active', lifecycle_state='Deployed'
        WHERE id=?
    ")->execute([$employeeId, $assetId]);

    addAuditLog('Assigned Spare Computer', "Asset ID: $assetId → Employee ID: $employeeId");
    $_SESSION['flash_msg']  = 'Spare computer assigned successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: inventory.php');
    exit;
}

// ── POST: Replace (swap old asset with spare) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'replace_spare') {
    $spareId   = intval($_POST['spare_id']);
    $replaceId = intval($_POST['replace_id']);   // asset being replaced

    // Get employee from the asset being replaced
    $old = $db->prepare("SELECT employee_id, asset_tag FROM assets WHERE id=?");
    $old->execute([$replaceId]);
    $oldAsset = $old->fetch(PDO::FETCH_ASSOC);

    if ($oldAsset) {
        // Move old asset to Retired/Spare
        $db->prepare("UPDATE assets SET status='Retired', employee_id=NULL WHERE id=?")
           ->execute([$replaceId]);

        // Assign spare to the same employee
        $db->prepare("UPDATE assets SET employee_id=?, status='Active', lifecycle_state='Deployed' WHERE id=?")
           ->execute([$oldAsset['employee_id'], $spareId]);

        addAuditLog('Replaced Computer with Spare',
            "Spare ID: $spareId replaced Asset ID: $replaceId (tag: {$oldAsset['asset_tag']})");
        $_SESSION['flash_msg']  = 'Computer replaced with spare successfully.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg']  = 'Original asset not found.';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: inventory.php');
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$status    = $_GET['status']    ?? '';
$dept      = $_GET['dept']      ?? '';
$lifecycle = $_GET['lifecycle'] ?? '';
$search    = $_GET['search']    ?? '';

$where  = [];
$params = [];
if ($status)    { $where[] = "a.status = ?";          $params[] = $status; }
if ($lifecycle) { $where[] = "a.lifecycle_state = ?"; $params[] = $lifecycle; }
if ($search) {
    $like    = "%$search%";
    $where[] = "(a.asset_tag LIKE ? OR a.serial_number LIKE ? OR e.name LIKE ? OR e.emp_id LIKE ? OR a.processor LIKE ? OR a.hostname LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($dept) { $where[] = "d.name = ?"; $params[] = $dept; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT a.*, e.name AS emp_name, e.emp_id, e.position,
           d.name AS dept_name
    FROM assets a
    LEFT JOIN employees e ON a.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    $whereSQL
    ORDER BY a.asset_tag ASC
    LIMIT 200
");
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalCount  = intval($db->query("SELECT COUNT(*) FROM assets")->fetchColumn());
$activeCount = intval($db->query("SELECT COUNT(*) FROM assets WHERE status='Active'")->fetchColumn());
$maintCount  = intval($db->query("SELECT COUNT(*) FROM assets WHERE status='Maintenance'")->fetchColumn());
$spareCount  = intval($db->query("SELECT COUNT(*) FROM assets WHERE status='Spare'")->fetchColumn());
$totalVal    = floatval($db->query("SELECT COALESCE(SUM(purchase_cost),0) FROM assets")->fetchColumn());

// Departments list
$depts = $db->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Employees for assign modal
$employees = $db->query("SELECT id, name, emp_id FROM employees WHERE is_active=1 ORDER BY name")
                ->fetchAll(PDO::FETCH_ASSOC);

// Spare assets for replace modal
$spareAssets = $db->query("SELECT id, asset_tag, brand, model FROM assets WHERE status='Spare' ORDER BY asset_tag")
                  ->fetchAll(PDO::FETCH_ASSOC);

// Active assets (for replace target)
$activeAssets = $db->query("
    SELECT a.id, a.asset_tag, a.brand, a.model, e.name AS emp_name
    FROM assets a
    LEFT JOIN employees e ON a.employee_id = e.id
    WHERE a.status='Active'
    ORDER BY a.asset_tag
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-server"></i> Computer Inventory</h2>
    <div style="display:flex;gap:9px">
        <?php if (hasRole('Admin','Technician')): ?>
        <button class="btn btn-secondary" id="btnAddSpare">
            <i class="fas fa-plus"></i> Add Spare
        </button>
        <a class="btn btn-success" href="inventory_add.php">
            <i class="fas fa-plus"></i> Add Computer
        </a>
        <?php endif; ?>
        <?php if (hasRole('Admin')): ?>
        <button class="btn btn-info" onclick="exportTableToExcel('assetTable','inventory_export')">
            <i class="fas fa-file-excel"></i> Export
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Flash -->
<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg']  ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<!-- Stats -->
<div class="stat-grid">
    <a class="stat-card" href="inventory.php">
        <div class="stat-title">Total</div><div class="stat-value"><?= $totalCount ?></div>
    </a>
    <a class="stat-card" href="inventory.php?status=Active">
        <div class="stat-title">Active</div><div class="stat-value"><?= $activeCount ?></div>
    </a>
    <a class="stat-card" href="inventory.php?status=Maintenance">
        <div class="stat-title">Maintenance</div><div class="stat-value"><?= $maintCount ?></div>
    </a>
    <a class="stat-card" href="inventory.php?status=Spare">
        <div class="stat-title">Spare</div><div class="stat-value"><?= $spareCount ?></div>
    </a>
    <div class="stat-card">
        <div class="stat-title">Total Value</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($totalVal) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="inventory.php">
<div class="filter-bar">
    <input class="filter-select" name="search"
           placeholder="Search tag, name, serial, CPU..."
           value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="dept">
        <option value="">All Departments</option>
        <?php foreach ($depts as $d): ?>
        <option value="<?= sanitize($d['name']) ?>" <?= $dept===$d['name']?'selected':'' ?>>
            <?= sanitize($d['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select class="filter-select" name="status">
        <option value="">All Status</option>
        <?php foreach (['Active','Maintenance','Spare','Retired','Lost'] as $s): ?>
        <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <select class="filter-select" name="lifecycle">
        <option value="">All Lifecycle</option>
        <?php foreach (['Purchased','Deployed','Active','Maintenance','Retired'] as $lc): ?>
        <option value="<?= $lc ?>" <?= $lifecycle===$lc?'selected':'' ?>><?= $lc ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-search"></i> Filter</button>
    <a href="inventory.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
</div>
</form>

<!-- Table -->
<div class="table-container">
    <p style="color:var(--text-muted);margin-bottom:14px;font-size:.9rem">
        Showing <?= count($assets) ?> record(s)
    </p>
    <table id="assetTable">
        <thead>
            <tr>
                <th>Asset Tag</th>
                <th>Employee</th>
                <th>Department</th>
                <th>Brand / Model</th>
                <th>CPU / RAM</th>
                <th>IP</th>
                <th>Status</th>
                <th>Lifecycle</th>
                <th>Value</th>
                <th>Warranty</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($assets as $a):
            $warrantyNote = '';
            if (!empty($a['warranty_expiry'])) {
                $days = (strtotime($a['warranty_expiry']) - time()) / 86400;
                if ($days < 0)      $warrantyNote = '<br><small style="color:var(--danger)">Expired</small>';
                elseif ($days < 30) $warrantyNote = '<br><small style="color:var(--warning)">Expiring soon</small>';
            }
            $isSpare = (strtolower($a['status']) === 'spare');
        ?>
        <tr>
            <td>
                <strong><?= sanitize($a['asset_tag']) ?></strong>
                <br><small style="color:var(--text-muted)"><?= sanitize($a['serial_number'] ?? '') ?></small>
            </td>
            <td>
                <?= sanitize($a['emp_name'] ?? '—') ?>
                <br><small><?= sanitize($a['emp_id'] ?? '') ?></small>
            </td>
            <td><?= sanitize($a['dept_name'] ?? '—') ?></td>
            <td><?= sanitize(($a['brand'] ?? '') . ' ' . ($a['model'] ?? '')) ?></td>
            <td><?= sanitize($a['processor'] ?? '—') ?><br><?= sanitize($a['ram'] ?? '') ?></td>
            <td><small><?= sanitize($a['ip_address'] ?? '—') ?></small></td>
            <td>
                <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $a['status'])) ?>">
                    <?= sanitize($a['status']) ?>
                </span>
            </td>
            <td><span class="badge badge-info"><?= sanitize($a['lifecycle_state'] ?? '') ?></span></td>
            <td><?= peso($a['purchase_cost'] ?? 0) ?></td>
            <td>
                <?= sanitize($a['warranty_expiry'] ?? '—') ?>
                <?= $warrantyNote ?>
            </td>
            <td style="white-space:nowrap">
                <a class="action-btn" href="asset_view.php?id=<?= $a['id'] ?>" title="View">
                    <i class="fas fa-eye"></i>
                </a>

                <?php if (hasRole('Admin','Technician')): ?>
                <!-- Assign button — only for Spare assets -->
                <?php if ($isSpare): ?>
                <button class="action-btn btn-assign-spare" title="Assign / Replace"
                    style="color:#28a745"
                    data-id="<?= (int)$a['id'] ?>"
                    data-tag="<?= htmlspecialchars($a['asset_tag'], ENT_QUOTES) ?>">
                    <i class="fas fa-user-plus"></i>
                </button>
                <?php endif; ?>

                <a class="action-btn" href="inventory_add.php?id=<?= $a['id'] ?>" title="Edit">
                    <i class="fas fa-edit"></i>
                </a>
                <button class="action-btn"
                    onclick="showQR('<?= addslashes($a['asset_tag'].'\nModel: '.($a['brand']??'').' '.($a['model']??'').'\nUser: '.($a['emp_name']??'')) ?>','<?= sanitize($a['asset_tag']) ?>')"
                    title="QR Code">
                    <i class="fas fa-qrcode"></i>
                </button>
                <button class="action-btn" onclick="pingDevice('<?= sanitize($a['ip_address'] ?? '') ?>')" title="Ping">
                    <i class="fas fa-network-wired"></i>
                </button>
                <?php endif; ?>

                <?php if (hasRole('Admin')): ?>
                <button class="action-btn" style="color:var(--danger)"
                    onclick="deleteAsset('<?= $a['id'] ?>','<?= sanitize($a['asset_tag']) ?>')"
                    title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($assets)): ?>
        <tr><td colspan="11">
            <div class="empty-state">
                <i class="fas fa-server"></i>
                <p>No computers found. <a href="inventory_add.php">Add one?</a></p>
            </div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- ══════════════════════════════════════════════════════
     MODAL STYLES
══════════════════════════════════════════════════════ -->
<style>
.inv-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 99999;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.inv-modal {
    background: #fff; border-radius: 12px;
    padding: 28px; width: 100%; max-width: 520px;
    max-height: 92vh; overflow-y: auto;
    position: relative;
    box-shadow: 0 12px 48px rgba(0,0,0,.25);
    animation: invSlide .18s ease;
}
.inv-modal-sm { max-width: 420px; }
@keyframes invSlide { from { opacity:0; transform:translateY(-14px); } to { opacity:1; transform:none; } }
.inv-modal h3 { margin: 0 0 20px; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 9px; }
.inv-mclose {
    position: absolute; top: 14px; right: 15px;
    background: none; border: none; font-size: 22px;
    cursor: pointer; color: #aaa; padding: 4px 8px; border-radius: 5px; line-height: 1;
}
.inv-mclose:hover { background: #f0f0f0; color: #333; }
.inv-fg { margin-bottom: 14px; }
.inv-fg label {
    display: block; font-size: 12px; font-weight: 700;
    margin-bottom: 5px; color: #555;
    text-transform: uppercase; letter-spacing: .3px;
}
.inv-fg input, .inv-fg select, .inv-fg textarea {
    display: block; width: 100%;
    padding: 9px 11px; border: 1.5px solid #dde1e7;
    border-radius: 7px; font-size: 13px; color: #333;
    background: #fff; font-family: inherit;
    transition: border-color .15s, box-shadow .15s;
}
.inv-fg input:focus, .inv-fg select:focus, .inv-fg textarea:focus {
    outline: none; border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,.12);
}
.inv-frow { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.inv-mfoot { display: flex; justify-content: flex-end; gap: 9px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
.inv-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; transition: filter .15s; }
.inv-btn:hover { filter: brightness(.91); }
.inv-btn-green  { background: #28a745; color: #fff; }
.inv-btn-blue   { background: #007bff; color: #fff; }
.inv-btn-orange { background: #fd7e14; color: #fff; }
.inv-btn-gray   { background: #6c757d; color: #fff; }
/* tab switcher */
.inv-tabs { display: flex; gap: 0; margin-bottom: 20px; border-radius: 8px; overflow: hidden; border: 1.5px solid #dde1e7; }
.inv-tab { flex: 1; padding: 10px; border: none; background: #f8fafc; font-size: 13px; font-weight: 600; cursor: pointer; color: #555; transition: background .15s, color .15s; }
.inv-tab.active { background: #007bff; color: #fff; }
.inv-tab-panel { display: none; }
.inv-tab-panel.active { display: block; }
@media(max-width:600px){ .inv-frow { grid-template-columns: 1fr; } }
</style>


<!-- ══ MODAL: Add Spare Computer ══ -->
<div class="inv-overlay" id="inv_mAddSpare" style="display:none">
  <div class="inv-modal">
    <button type="button" class="inv-mclose js-inv-close" data-target="inv_mAddSpare">&times;</button>
    <h3><i class="fas fa-plus-circle" style="color:#28a745"></i> Add Spare Computer</h3>
    <form method="POST" action="inventory.php" autocomplete="off">
      <input type="hidden" name="action" value="add_spare">
      <div class="inv-frow">
        <div class="inv-fg">
          <label>Asset Tag <span style="color:red">*</span></label>
          <input type="text" name="asset_tag" required placeholder="e.g. SPARE-001">
        </div>
        <div class="inv-fg">
          <label>Brand <span style="color:red">*</span></label>
          <input type="text" name="brand" required placeholder="e.g. Dell">
        </div>
      </div>
      <div class="inv-frow">
        <div class="inv-fg">
          <label>Model <span style="color:red">*</span></label>
          <input type="text" name="model" required placeholder="e.g. Latitude 5520">
        </div>
        <div class="inv-fg">
          <label>Serial Number</label>
          <input type="text" name="serial_number" placeholder="Optional">
        </div>
      </div>
      <div class="inv-frow">
        <div class="inv-fg">
          <label>Processor</label>
          <input type="text" name="processor" placeholder="e.g. Intel i5">
        </div>
        <div class="inv-fg">
          <label>RAM</label>
          <input type="text" name="ram" placeholder="e.g. 8GB">
        </div>
      </div>
      <div class="inv-frow">
        <div class="inv-fg">
          <label>Storage</label>
          <input type="text" name="storage" placeholder="e.g. 256GB SSD">
        </div>
        <div class="inv-fg">
          <label>Purchase Cost (&#8369;)</label>
          <input type="number" name="purchase_cost" step="0.01" min="0" value="0">
        </div>
      </div>
      <div class="inv-frow">
        <div class="inv-fg">
          <label>Purchase Date</label>
          <input type="date" name="purchase_date">
        </div>
        <div class="inv-fg">
          <label>Warranty Expiry</label>
          <input type="date" name="warranty_expiry">
        </div>
      </div>

      <div class="inv-mfoot">
        <button type="button" class="inv-btn inv-btn-gray js-inv-close" data-target="inv_mAddSpare">Cancel</button>
        <button type="submit" class="inv-btn inv-btn-green"><i class="fas fa-save"></i> Save Spare</button>
      </div>
    </form>
  </div>
</div>


<!-- ══ MODAL: Assign / Replace Spare ══ -->
<div class="inv-overlay" id="inv_mAssignSpare" style="display:none">
  <div class="inv-modal">
    <button type="button" class="inv-mclose js-inv-close" data-target="inv_mAssignSpare">&times;</button>
    <h3><i class="fas fa-exchange-alt" style="color:#007bff"></i> Assign / Replace Spare</h3>

    <div style="background:#f0f7ff;border-radius:7px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#004085">
      <i class="fas fa-info-circle"></i>
      Spare: <strong id="spareTagLabel"></strong>
    </div>

    <!-- Tab switcher -->
    <div class="inv-tabs">
      <button type="button" class="inv-tab active" onclick="switchTab('assign')">
        <i class="fas fa-user-plus"></i> Assign to Employee
      </button>
      <button type="button" class="inv-tab" onclick="switchTab('replace')">
        <i class="fas fa-sync-alt"></i> Replace Existing
      </button>
    </div>

    <!-- Tab: Assign to Employee -->
    <div class="inv-tab-panel active" id="tab_assign">
      <form method="POST" action="inventory.php">
        <input type="hidden" name="action" value="assign_spare">
        <input type="hidden" name="asset_id" id="assign_asset_id">
        <div class="inv-fg">
          <label>Select Employee <span style="color:red">*</span></label>
          <select name="employee_id" required>
            <option value="">— Choose employee —</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>">
              <?= htmlspecialchars($e['name']) ?> (<?= htmlspecialchars($e['emp_id']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p style="font-size:12px;color:#666;margin-bottom:4px">
          The spare will be marked <strong>Active</strong> and assigned to the selected employee.
        </p>
        <div class="inv-mfoot">
          <button type="button" class="inv-btn inv-btn-gray js-inv-close" data-target="inv_mAssignSpare">Cancel</button>
          <button type="submit" class="inv-btn inv-btn-blue"><i class="fas fa-check"></i> Assign</button>
        </div>
      </form>
    </div>

    <!-- Tab: Replace Existing -->
    <div class="inv-tab-panel" id="tab_replace">
      <form method="POST" action="inventory.php">
        <input type="hidden" name="action" value="replace_spare">
        <input type="hidden" name="spare_id" id="replace_spare_id">
        <div class="inv-fg">
          <label>Computer to Replace <span style="color:red">*</span></label>
          <select name="replace_id" required>
            <option value="">— Choose computer to replace —</option>
            <?php foreach ($activeAssets as $aa): ?>
            <option value="<?= (int)$aa['id'] ?>">
              <?= htmlspecialchars($aa['asset_tag']) ?>
              — <?= htmlspecialchars(($aa['brand'] ?? '') . ' ' . ($aa['model'] ?? '')) ?>
              <?php if (!empty($aa['emp_name'])): ?>
                (<?= htmlspecialchars($aa['emp_name']) ?>)
              <?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:7px;padding:10px 14px;font-size:12px;color:#7b5e00;margin-bottom:4px">
          <i class="fas fa-exclamation-triangle"></i>
          The replaced computer will be marked <strong>Retired</strong> and its employee will be reassigned to this spare.
        </div>
        <div class="inv-mfoot">
          <button type="button" class="inv-btn inv-btn-gray js-inv-close" data-target="inv_mAssignSpare">Cancel</button>
          <button type="submit" class="inv-btn inv-btn-orange"><i class="fas fa-sync-alt"></i> Replace</button>
        </div>
      </form>
    </div>

  </div>
</div>


<!-- Hidden delete form -->
<form id="deleteAssetForm" method="POST" action="../api/assets.php">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteAssetId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {

  function g(id) { return document.getElementById(id); }
  function openM(id)  { var el = g(id); if (el) el.style.display = 'flex'; }
  function closeM(id) { var el = g(id); if (el) el.style.display = 'none'; }

  /* backdrop close */
  document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('inv-overlay')) {
      e.target.style.display = 'none';
    }
  });

  /* close buttons */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-inv-close');
    if (btn) closeM(btn.getAttribute('data-target'));
  });

  /* ESC */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.inv-overlay').forEach(function (o) {
        o.style.display = 'none';
      });
    }
  });

  /* Add Spare button */
  var btnSpare = g('btnAddSpare');
  if (btnSpare) {
    btnSpare.addEventListener('click', function () { openM('inv_mAddSpare'); });
  }

  /* Assign/Replace spare row buttons */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-assign-spare');
    if (!btn) return;
    var id  = btn.getAttribute('data-id');
    var tag = btn.getAttribute('data-tag');
    g('spareTagLabel').textContent = tag;
    g('assign_asset_id').value     = id;
    g('replace_spare_id').value    = id;
    switchTab('assign');    // always open on assign tab
    openM('inv_mAssignSpare');
  });

});

/* Tab switcher */
function switchTab(name) {
  document.querySelectorAll('.inv-tab').forEach(function (t, i) {
    t.classList.toggle('active', i === (name === 'assign' ? 0 : 1));
  });
  document.getElementById('tab_assign') .classList.toggle('active', name === 'assign');
  document.getElementById('tab_replace').classList.toggle('active', name === 'replace');
}

/* Delete */
function deleteAsset(id, tag) {
  if (!confirm('Delete asset ' + tag + '? This cannot be undone.')) return;
  document.getElementById('deleteAssetId').value = id;
  document.getElementById('deleteAssetForm').submit();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>