<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Technician');

$pageTitle   = 'Add / Edit Asset';
$currentPage = 'inventory';
$db = db();

$id     = trim($_GET['id'] ?? '');
$isEdit = false;
$asset  = [];

if ($id) {
    $stmt = $db->prepare("SELECT a.*, e.emp_id, e.name AS emp_name, e.position, e.email, e.phone,
        d.name AS dept_name
        FROM assets a
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE a.id = ?");
    $stmt->execute([$id]);
    $asset  = $stmt->fetch() ?: [];
    $isEdit = !empty($asset);
    if ($id && !$isEdit) { header('Location: inventory.php'); exit; }
}

if (isPost()) {
    $empId   = strtoupper(trim($_POST['emp_id'] ?? ''));
    $empName = trim($_POST['emp_name'] ?? '');
    $dept    = trim($_POST['dept'] ?? '');
    $employeeId = null;

    if ($empId && $empName) {
        // Find or create department
        $deptId = null;
        if ($dept) {
            $dStmt = $db->prepare("SELECT id FROM departments WHERE name=?");
            $dStmt->execute([$dept]);
            $deptId = $dStmt->fetchColumn();
            if (!$deptId) {
                $db->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$dept]);
                $deptId = $db->lastInsertId();
            }
        }
        // Find or create employee
        $eStmt = $db->prepare("SELECT id FROM employees WHERE emp_id=?");
        $eStmt->execute([$empId]);
        $empRow = $eStmt->fetchColumn();
        if ($empRow) {
            $db->prepare("UPDATE employees SET name=?, department_id=?, position=?, email=?, phone=? WHERE emp_id=?")
               ->execute([$empName, $deptId, trim($_POST['position']??''), trim($_POST['email']??''), trim($_POST['phone']??''), $empId]);
            $employeeId = $empRow;
        } else {
            $db->prepare("INSERT INTO employees (emp_id, name, department_id, position, email, phone) VALUES (?,?,?,?,?,?)")
               ->execute([$empId, $empName, $deptId, trim($_POST['position']??''), trim($_POST['email']??''), trim($_POST['phone']??'')]);
            $employeeId = $db->lastInsertId();
        }
    }

    $fields = [
        'asset_tag'          => strtoupper(trim($_POST['asset_tag']??'')),
        'serial_number'      => trim($_POST['serial_number']??''),
        'brand'              => trim($_POST['brand']??''),
        'model'              => trim($_POST['model']??''),
        'device_type'        => trim($_POST['device_type']??'Desktop'),
        'processor'          => trim($_POST['processor']??''),
        'ram'                => trim($_POST['ram']??''),
        'storage'            => trim($_POST['storage']??''),
        'gpu'                => trim($_POST['gpu']??''),
        'monitor'            => trim($_POST['monitor']??''),
        'operating_system'   => trim($_POST['operating_system']??''),
        'os_version'         => trim($_POST['os_version']??''),
        'hostname'           => trim($_POST['hostname']??''),
        'ip_address'         => trim($_POST['ip_address']??''),
        'mac_address'        => trim($_POST['mac_address']??''),
        'connection_type'    => trim($_POST['connection_type']??''),
        'isp'                => trim($_POST['isp']??''),
        'purchase_date'      => $_POST['purchase_date']??null ?: null,
        'supplier'           => trim($_POST['supplier']??''),
        'po_number'          => trim($_POST['po_number']??''),
        'purchase_cost'      => floatval($_POST['purchase_cost']??0),
        'warranty_expiry'    => $_POST['warranty_expiry']??null ?: null,
        'status'             => $_POST['status']??'Active',
        'lifecycle_state'    => $_POST['lifecycle_state']??'Active',
        'company'            => trim($_POST['company']??'Main Office'),
        'building'           => trim($_POST['building']??''),
        'floor'              => trim($_POST['floor']??''),
        'room'               => trim($_POST['room']??''),
        'desk'               => trim($_POST['desk']??''),
        'antivirus_installed'=> intval($_POST['antivirus_installed']??1),
        'antivirus_name'     => trim($_POST['antivirus_name']??'Windows Defender'),
        'firewall_enabled'   => intval($_POST['firewall_enabled']??1),
        'encryption_enabled' => intval($_POST['encryption_enabled']??0),
        'is_flagged'         => intval($_POST['is_flagged']??0),
        'flag_reason'        => trim($_POST['flag_reason']??''),
        'employee_id'        => $employeeId,
    ];

    if (!$fields['asset_tag']) {
        $_SESSION['flash_msg']  = 'Asset Tag is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . ($isEdit ? "inventory_add.php?id=$id" : 'inventory_add.php'));
        exit;
    }

    if ($isEdit) {
        $setCols = implode('=?, ', array_keys($fields)) . '=?';
        $vals    = array_values($fields);
        $vals[]  = $id;
        $db->prepare("UPDATE assets SET $setCols WHERE id=?")->execute($vals);
        addAuditLog('Updated Asset', $fields['asset_tag']);
        $_SESSION['flash_msg'] = 'Asset '.$fields['asset_tag'].' updated.';
    } else {
        $cols = implode(', ', array_keys($fields));
        $pls  = implode(', ', array_fill(0, count($fields), '?'));
        $db->prepare("INSERT INTO assets ($cols) VALUES ($pls)")->execute(array_values($fields));
        addAuditLog('Added Asset', $fields['asset_tag']);
        $_SESSION['flash_msg'] = 'Asset '.$fields['asset_tag'].' added.';
    }
    $_SESSION['flash_type'] = 'success';
    header('Location: inventory.php');
    exit;
}

$depts   = $db->query("SELECT name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$deviceTypes = ['Desktop','Laptop','Server','Tablet','Printer','Monitor','Network Device','Other'];
$statuses    = ['Active','Maintenance','Spare','Retired','Lost','Stolen','Disposed'];
$lifecycles  = ['Purchased','Deployed','Active','Maintenance','Retired'];
$connTypes   = ['Wired','WiFi','Both','None'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-<?= $isEdit ? 'edit' : 'plus-circle' ?>"></i> <?= $isEdit ? 'Edit Asset — '.sanitize($asset['asset_tag']) : 'Add New Asset' ?></h2>
    <a href="inventory.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<form method="POST">
<!-- Employee Info -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-user"></i> Employee / Assignment</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Employee ID</label>
            <input name="emp_id" value="<?= sanitize($asset['emp_id'] ?? '') ?>" placeholder="e.g. EMP00001">
        </div>
        <div class="form-group">
            <label>Employee Name</label>
            <input name="emp_name" value="<?= sanitize($asset['emp_name'] ?? '') ?>" placeholder="Full name">
        </div>
        <div class="form-group">
            <label>Department</label>
            <input name="dept" list="deptList" value="<?= sanitize($asset['dept_name'] ?? '') ?>" placeholder="Select or type department">
            <datalist id="deptList">
                <?php foreach ($depts as $d): ?><option value="<?= sanitize($d) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div class="form-group">
            <label>Position</label>
            <input name="position" value="<?= sanitize($asset['position'] ?? '') ?>" placeholder="Job title">
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= sanitize($asset['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input name="phone" value="<?= sanitize($asset['phone'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- Asset Info -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-server"></i> Asset Information</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Asset Tag *</label>
            <input name="asset_tag" required value="<?= sanitize($asset['asset_tag'] ?? '') ?>" placeholder="e.g. PC-00001">
        </div>
        <div class="form-group">
            <label>Serial Number</label>
            <input name="serial_number" value="<?= sanitize($asset['serial_number'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Brand</label>
            <input name="brand" value="<?= sanitize($asset['brand'] ?? '') ?>" placeholder="e.g. Dell, HP, Lenovo">
        </div>
        <div class="form-group">
            <label>Model</label>
            <input name="model" value="<?= sanitize($asset['model'] ?? '') ?>" placeholder="e.g. OptiPlex 7090">
        </div>
        <div class="form-group">
            <label>Device Type</label>
            <select name="device_type">
                <?php foreach ($deviceTypes as $t): ?>
                <option value="<?= $t ?>" <?= ($asset['device_type']??'Desktop')===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Company / Branch</label>
            <input name="company" value="<?= sanitize($asset['company'] ?? 'Main Office') ?>">
        </div>
    </div>
</div>

<!-- Hardware Specs -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-microchip"></i> Hardware Specifications</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Processor / CPU</label>
            <input name="processor" value="<?= sanitize($asset['processor'] ?? '') ?>" placeholder="e.g. Intel Core i7-12700">
        </div>
        <div class="form-group">
            <label>RAM</label>
            <input name="ram" value="<?= sanitize($asset['ram'] ?? '') ?>" placeholder="e.g. 16GB DDR4">
        </div>
        <div class="form-group">
            <label>Storage</label>
            <input name="storage" value="<?= sanitize($asset['storage'] ?? '') ?>" placeholder="e.g. 512GB SSD">
        </div>
        <div class="form-group">
            <label>GPU / Graphics</label>
            <input name="gpu" value="<?= sanitize($asset['gpu'] ?? '') ?>" placeholder="e.g. NVIDIA RTX 3060">
        </div>
        <div class="form-group">
            <label>Monitor</label>
            <input name="monitor" value="<?= sanitize($asset['monitor'] ?? '') ?>" placeholder="e.g. Dell 24inch FHD">
        </div>
        <div class="form-group">
            <label>Operating System</label>
            <input name="operating_system" list="osList" value="<?= sanitize($asset['operating_system'] ?? '') ?>" placeholder="e.g. Windows 11 Pro">
            <datalist id="osList">
                <option value="Windows 11 Pro"><option value="Windows 11 Home"><option value="Windows 10 Pro">
                <option value="Windows 10 Home"><option value="Ubuntu 22.04"><option value="macOS Ventura">
            </datalist>
        </div>
        <div class="form-group">
            <label>OS Version</label>
            <input name="os_version" value="<?= sanitize($asset['os_version'] ?? '') ?>" placeholder="e.g. 22H2">
        </div>
    </div>
</div>

<!-- Network -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-network-wired"></i> Network</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Hostname</label>
            <input name="hostname" value="<?= sanitize($asset['hostname'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>IP Address</label>
            <input name="ip_address" value="<?= sanitize($asset['ip_address'] ?? '') ?>" placeholder="e.g. 192.168.1.100">
        </div>
        <div class="form-group">
            <label>MAC Address</label>
            <input name="mac_address" value="<?= sanitize($asset['mac_address'] ?? '') ?>" placeholder="e.g. AA:BB:CC:DD:EE:FF">
        </div>
        <div class="form-group">
            <label>Connection Type</label>
            <select name="connection_type">
                <?php foreach ($connTypes as $c): ?>
                <option value="<?= $c ?>" <?= ($asset['connection_type']??'')===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>ISP</label>
            <input name="isp" value="<?= sanitize($asset['isp'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- Location -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-map-marker-alt"></i> Location</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Building</label>
            <input name="building" value="<?= sanitize($asset['building'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Floor</label>
            <input name="floor" value="<?= sanitize($asset['floor'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Room</label>
            <input name="room" value="<?= sanitize($asset['room'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Desk</label>
            <input name="desk" value="<?= sanitize($asset['desk'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- Purchase Info -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-peso-sign"></i> Purchase & Status</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Purchase Date</label>
            <input type="date" name="purchase_date" value="<?= sanitize($asset['purchase_date'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Supplier / Vendor</label>
            <input name="supplier" value="<?= sanitize($asset['supplier'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>PO Number</label>
            <input name="po_number" value="<?= sanitize($asset['po_number'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Purchase Cost (₱)</label>
            <input type="number" name="purchase_cost" step="0.01" value="<?= $asset['purchase_cost'] ?? '0' ?>">
        </div>
        <div class="form-group">
            <label>Warranty Expiry</label>
            <input type="date" name="warranty_expiry" value="<?= sanitize($asset['warranty_expiry'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status">
                <?php foreach ($statuses as $s): ?>
                <option value="<?= $s ?>" <?= ($asset['status']??'Active')===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Lifecycle State</label>
            <select name="lifecycle_state">
                <?php foreach ($lifecycles as $l): ?>
                <option value="<?= $l ?>" <?= ($asset['lifecycle_state']??'Active')===$l?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<!-- Security -->
<div class="table-container" style="margin-bottom:20px">
    <h4 style="margin-bottom:16px"><i class="fas fa-shield-alt"></i> Security</h4>
    <div class="form-grid">
        <div class="form-group">
            <label>Antivirus Installed</label>
            <select name="antivirus_installed">
                <option value="1" <?= ($asset['antivirus_installed']??1)?'selected':'' ?>>Yes</option>
                <option value="0" <?= !($asset['antivirus_installed']??1)?'selected':'' ?>>No</option>
            </select>
        </div>
        <div class="form-group">
            <label>Antivirus Name</label>
            <input name="antivirus_name" value="<?= sanitize($asset['antivirus_name'] ?? 'Windows Defender') ?>">
        </div>
        <div class="form-group">
            <label>Firewall</label>
            <select name="firewall_enabled">
                <option value="1" <?= ($asset['firewall_enabled']??1)?'selected':'' ?>>Enabled</option>
                <option value="0" <?= !($asset['firewall_enabled']??1)?'selected':'' ?>>Disabled</option>
            </select>
        </div>
        <div class="form-group">
            <label>Encryption</label>
            <select name="encryption_enabled">
                <option value="0" <?= !($asset['encryption_enabled']??0)?'selected':'' ?>>Disabled</option>
                <option value="1" <?= ($asset['encryption_enabled']??0)?'selected':'' ?>>Enabled</option>
            </select>
        </div>
        <div class="form-group">
            <label>Flag Asset</label>
            <select name="is_flagged">
                <option value="0" <?= !($asset['is_flagged']??0)?'selected':'' ?>>No</option>
                <option value="1" <?= ($asset['is_flagged']??0)?'selected':'' ?>>Yes — Flag this asset</option>
            </select>
        </div>
        <div class="form-group">
            <label>Flag Reason</label>
            <input name="flag_reason" value="<?= sanitize($asset['flag_reason'] ?? '') ?>" placeholder="e.g. Lost, Stolen, Suspicious">
        </div>
    </div>
</div>

<div style="display:flex;gap:12px;margin-bottom:40px">
    <button type="submit" class="btn btn-success" style="padding:12px 32px;font-size:1rem">
        <i class="fas fa-save"></i> <?= $isEdit ? 'Update Asset' : 'Save Asset' ?>
    </button>
    <a href="inventory.php" class="btn btn-secondary" style="padding:12px 32px">Cancel</a>
</div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
