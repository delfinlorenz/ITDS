<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Employees';
$currentPage = 'employees';
$db = db();

// ── POST handlers ──────────────────────────────────────────
if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && hasRole('Admin','Technician','Manager')) {
        $deptId = intval($_POST['department_id'] ?? 0);
        // Auto-create dept if typed but not in list
        if (!$deptId && !empty(trim($_POST['dept_name_new'] ?? ''))) {
            $db->prepare("INSERT IGNORE INTO departments (name) VALUES (?)")->execute([trim($_POST['dept_name_new'])]);
            $deptId = $db->lastInsertId() ?: $db->query("SELECT id FROM departments WHERE name='".trim($_POST['dept_name_new'])."'")->fetchColumn();
        }
        $empId = strtoupper(trim($_POST['emp_id'])) ?: 'EMP'.str_pad($db->query("SELECT COUNT(*)+1 FROM employees")->fetchColumn(), 5, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO employees (emp_id, name, department_id, position, email, phone, building, floor, room, is_active)
                      VALUES (?,?,?,?,?,?,?,?,?,1)")
           ->execute([$empId, trim($_POST['name']), $deptId ?: null, trim($_POST['position']??''),
                      trim($_POST['email']??''), trim($_POST['phone']??''),
                      trim($_POST['building']??''), trim($_POST['floor']??''), trim($_POST['room']??'')]);
        addAuditLog('Added Employee', $empId.' - '.trim($_POST['name']));
        $_SESSION['flash_msg']  = 'Employee '.trim($_POST['name']).' added successfully.';
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'edit' && hasRole('Admin','Technician','Manager')) {
        $id     = intval($_POST['id']);
        $deptId = intval($_POST['department_id'] ?? 0);
        if (!$deptId && !empty(trim($_POST['dept_name_new'] ?? ''))) {
            $db->prepare("INSERT IGNORE INTO departments (name) VALUES (?)")->execute([trim($_POST['dept_name_new'])]);
            $deptId = $db->lastInsertId() ?: $db->query("SELECT id FROM departments WHERE name='".trim($_POST['dept_name_new'])."'")->fetchColumn();
        }
        $db->prepare("UPDATE employees SET emp_id=?,name=?,department_id=?,position=?,email=?,phone=?,building=?,floor=?,room=?,is_active=? WHERE id=?")
           ->execute([trim($_POST['emp_id']), trim($_POST['name']), $deptId ?: null,
                      trim($_POST['position']??''), trim($_POST['email']??''), trim($_POST['phone']??''),
                      trim($_POST['building']??''), trim($_POST['floor']??''), trim($_POST['room']??''),
                      intval($_POST['is_active']??1), $id]);
        addAuditLog('Updated Employee', 'ID '.$id.' - '.trim($_POST['name']));
        $_SESSION['flash_msg']  = 'Employee updated.';
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'delete' && hasRole('Admin')) {
        $id = intval($_POST['id']);
        // Soft delete
        $db->prepare("UPDATE employees SET is_active=0 WHERE id=?")->execute([$id]);
        addAuditLog('Deactivated Employee', 'ID '.$id);
        $_SESSION['flash_msg']  = 'Employee deactivated.';
        $_SESSION['flash_type'] = 'warning';
    }

    header('Location: employees.php');
    exit;
}

// ── Filters ──────────────────────────────────────────────
$search     = $_GET['search'] ?? '';
$deptFilter = $_GET['dept']   ?? '';
$showAll    = $_GET['show']   ?? 'active';

$where  = []; $params = [];
if ($search)    { 
    $where[] = "(e.name LIKE ? OR e.emp_id LIKE ? OR e.email LIKE ? OR e.position LIKE ?)"; 
    $like="%$search%"; 
    $params=array_merge($params,[$like,$like,$like,$like]); 
}
if ($deptFilter){ $where[] = "d.name=?"; $params[] = $deptFilter; }
if ($showAll !== 'all') { $where[] = "e.is_active=1"; }
$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

$employees = $db->prepare("SELECT e.*, d.name AS dept_name,
    COUNT(DISTINCT a.id) AS device_count,
    COALESCE(SUM(a.purchase_cost),0) AS total_value
    FROM employees e
    LEFT JOIN departments d  ON e.department_id = d.id
    LEFT JOIN assets a       ON a.employee_id   = e.id
    $whereSQL
    GROUP BY e.id
    ORDER BY e.is_active DESC, e.name ASC LIMIT 300");
$employees->execute($params);
$employees = $employees->fetchAll();

$depts  = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

$total  = $db->query("SELECT COUNT(*) FROM employees WHERE is_active=1")->fetchColumn();
$inactive = $db->query("SELECT COUNT(*) FROM employees WHERE is_active=0")->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-users"></i> Employee Directory</h2>
    <div style="display:flex;gap:9px;align-items:center">
        <span style="color:var(--text-muted);font-size:.9rem"><?= $total ?> active</span>
        <?php if (hasRole('Admin','Technician','Manager')): ?>
        <button class="btn btn-success" onclick="document.getElementById('addEmpModal').classList.add('show')">
            <i class="fas fa-user-plus"></i> Add Employee
        </button>
        <?php endif; ?>
        <button class="btn btn-info" onclick="exportTableToExcel('empTable','employees_export')">
            <i class="fas fa-file-excel"></i> Export
        </button>
    </div>
</div>

<!-- Flash message -->
<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg'] ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card"><div class="stat-title">Total Active</div><div class="stat-value"><?= $total ?></div></div>
    <div class="stat-card"><div class="stat-title">Departments</div><div class="stat-value"><?= count($depts) ?></div></div>
    <div class="stat-card"><div class="stat-title">Inactive</div><div class="stat-value"><?= $inactive ?></div></div>
    <div class="stat-card"><div class="stat-title">Total Employees</div><div class="stat-value"><?= $total + $inactive ?></div></div>
</div>

<!-- Filters -->
<form method="GET">
<div class="filter-bar" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
    <input class="filter-select" name="search" placeholder="Search name, ID, email, position..." value="<?= sanitize($search) ?>" style="flex:2; min-width: 200px;">
    
    <select class="filter-select" name="dept" style="min-width: 150px;">
        <option value="">All Departments</option>
        <?php foreach ($depts as $d): ?>
        <option value="<?= sanitize($d['name']) ?>" <?= $deptFilter===$d['name']?'selected':'' ?>><?= sanitize($d['name']) ?></option>
        <?php endforeach; ?>
    </select>
    
    <select class="filter-select" name="show" style="min-width: 120px;">
        <option value="active" <?= $showAll!=='all'?'selected':'' ?>>Active Only</option>
        <option value="all"    <?= $showAll==='all'?'selected':'' ?>>Show All</option>
    </select>
    
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    <a href="employees.php" class="btn btn-secondary">Clear</a>
</div>
</form>

<!-- Table -->
<div class="table-container">
    <table id="empTable" class="table table-striped">
        <thead>
            <tr>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Position</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Devices</th>
                <th>Asset Value</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
        <tr <?= !$e['is_active'] ? 'style="opacity:.55"' : '' ?>>
            <td><strong><?= sanitize($e['emp_id'] ?? '—') ?></strong></td>
            <td><?= sanitize($e['name']) ?></td>
            <td><?= sanitize($e['dept_name'] ?? '—') ?></td>
            <td><?= sanitize($e['position'] ?? '—') ?></td>
            <td>
                <?php if (!empty($e['email'])): ?>
                <a href="mailto:<?= sanitize($e['email']) ?>"><?= sanitize($e['email']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <?php if (!empty($e['phone'])): ?>
                <a href="tel:<?= sanitize($e['phone']) ?>"><?= sanitize($e['phone']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge badge-info"><?= $e['device_count'] ?></span></td>
            <td><?= peso($e['total_value']) ?></td>
            <td>
                <span class="badge badge-<?= $e['is_active'] ? 'success' : 'danger' ?>">
                    <i class="fas fa-<?= $e['is_active'] ? 'check-circle' : 'minus-circle' ?>"></i>
                    <?= $e['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </td>
            <td>
                <div style="display: flex; gap: 5px;">
                    <a class="action-btn" href="inventory.php?search=<?= urlencode($e['name']) ?>" title="View Devices">
                        <i class="fas fa-desktop"></i>
                    </a>
                    <?php if (hasRole('Admin','Technician','Manager')): ?>
                    <button class="action-btn" title="Edit" onclick='openEditEmp(<?= json_encode($e) ?>)'>
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if (hasRole('Admin') && $e['is_active']): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Deactivate <?= sanitize($e['name']) ?>?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                        <button type="submit" class="action-btn" style="color:var(--danger)" title="Deactivate">
                            <i class="fas fa-user-slash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($employees)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="fas fa-users"></i><p>No employees found. Click <strong>Add Employee</strong> to get started.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ===== ADD EMPLOYEE MODAL ===== -->
<div class="modal" id="addEmpModal">
    <div class="modal-content" style="max-width:680px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:20px"><i class="fas fa-user-plus"></i> Add New Employee</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-grid">
                <div class="form-group">
                    <label>Employee ID <small style="color:var(--text-muted)">(auto if blank)</small></label>
                    <input name="emp_id" placeholder="e.g. EMP00001">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input name="name" required placeholder="e.g. Juan Dela Cruz">
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id" id="addDeptSelect" onchange="toggleNewDept('add',this.value)">
                        <option value="">Select Department</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="new">+ Add New Department</option>
                    </select>
                </div>
                <div class="form-group" id="addNewDeptGroup" style="display:none">
                    <label>New Department Name</label>
                    <input name="dept_name_new" id="addNewDeptInput" placeholder="Enter department name">
                </div>
                <div class="form-group">
                    <label>Position / Job Title</label>
                    <input name="position" placeholder="e.g. Software Engineer">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="e.g. juan@company.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input name="phone" placeholder="e.g. 09XX-XXX-XXXX">
                </div>
                
                <!-- Location Section (still in form but not displayed in table) -->
                <div class="form-group" style="grid-column: span 2;">
                    <h4 style="margin-bottom: 10px;"><i class="fas fa-map-marker-alt"></i> Location Details (Optional)</h4>
                </div>
                
                <div class="form-group">
                    <label>Building</label>
                    <input name="building" placeholder="e.g. Main Building">
                </div>
                <div class="form-group">
                    <label>Floor</label>
                    <input name="floor" placeholder="e.g. 3">
                </div>
                <div class="form-group">
                    <label>Room / Desk</label>
                    <input name="room" placeholder="e.g. Room 301">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Employee</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== EDIT EMPLOYEE MODAL ===== -->
<div class="modal" id="editEmpModal">
    <div class="modal-content" style="max-width:680px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:20px"><i class="fas fa-edit"></i> Edit Employee</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editEmpId">
            <div class="form-grid">
                <div class="form-group">
                    <label>Employee ID</label>
                    <input name="emp_id" id="editEmpIdField">
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input name="name" id="editEmpName" required>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id" id="editDeptSelect" onchange="toggleNewDept('edit',this.value)">
                        <option value="">Select Department</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="new">+ Add New Department</option>
                    </select>
                </div>
                <div class="form-group" id="editNewDeptGroup" style="display:none">
                    <label>New Department Name</label>
                    <input name="dept_name_new" id="editNewDeptInput" placeholder="Enter department name">
                </div>
                <div class="form-group">
                    <label>Position / Job Title</label>
                    <input name="position" id="editEmpPosition">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="editEmpEmail">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input name="phone" id="editEmpPhone">
                </div>
                
                <!-- Location Section (still in form but not displayed in table) -->
                <div class="form-group" style="grid-column: span 2;">
                    <h4 style="margin-bottom: 10px;"><i class="fas fa-map-marker-alt"></i> Location Details (Optional)</h4>
                </div>
                
                <div class="form-group">
                    <label>Building</label>
                    <input name="building" id="editEmpBuilding" placeholder="e.g. Main Building">
                </div>
                <div class="form-group">
                    <label>Floor</label>
                    <input name="floor" id="editEmpFloor" placeholder="e.g. 3">
                </div>
                <div class="form-group">
                    <label>Room / Desk</label>
                    <input name="room" id="editEmpRoom" placeholder="e.g. Room 301">
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label>Status</label>
                    <select name="is_active" id="editEmpActive">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update Employee</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Filter bar improvements */
.filter-bar {
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--border, #dee2e6);
    border-radius: 4px;
    background: white;
}

.btn-primary {
    background: var(--primary, #007bff);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
}

.btn-primary:hover {
    background: var(--primary-dark, #0056b3);
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
}

.btn-secondary:hover {
    background: #5a6268;
}

.action-btn {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
    margin: 0 5px;
    padding: 5px;
    color: var(--primary, #007bff);
}

.action-btn:hover {
    opacity: 0.7;
}

/* Responsive table */
@media (max-width: 1200px) {
    .table-container {
        overflow-x: auto;
    }
    
    table {
        min-width: 1000px;
    }
}
</style>

<script>
// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
});

function toggleNewDept(prefix, val) {
    const g = document.getElementById(prefix + 'NewDeptGroup');
    const i = document.getElementById(prefix + 'NewDeptInput');
    if (val === 'new') {
        g.style.display = '';
        i.setAttribute('required', 'required');
    } else {
        g.style.display = 'none';
        i.removeAttribute('required');
        i.value = '';
    }
}

function openEditEmp(e) {
    document.getElementById('editEmpId').value       = e.id;
    document.getElementById('editEmpIdField').value  = e.emp_id || '';
    document.getElementById('editEmpName').value     = e.name || '';
    document.getElementById('editDeptSelect').value  = e.department_id || '';
    document.getElementById('editEmpPosition').value = e.position || '';
    document.getElementById('editEmpEmail').value    = e.email || '';
    document.getElementById('editEmpPhone').value    = e.phone || '';
    document.getElementById('editEmpBuilding').value = e.building || '';
    document.getElementById('editEmpFloor').value    = e.floor || '';
    document.getElementById('editEmpRoom').value     = e.room || '';
    document.getElementById('editEmpActive').value   = e.is_active;
    document.getElementById('editNewDeptGroup').style.display = 'none';
    document.getElementById('editEmpModal').classList.add('show');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>