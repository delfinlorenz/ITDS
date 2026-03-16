<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin');

$pageTitle   = 'User Management';
$currentPage = 'users';
$db = db();

if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $un = trim($_POST['username']);
        $exists = $db->prepare("SELECT id FROM users WHERE username=?");
        $exists->execute([$un]);
        if ($exists->fetch()) {
            $_SESSION['flash_msg']  = "Username '$un' already exists.";
            $_SESSION['flash_type'] = 'danger';
        } else {
            $hash = password_hash(trim($_POST['password']), PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (name, username, email, password, role, company, is_active) VALUES (?,?,?,?,?,?,1)")
               ->execute([
                   trim($_POST['name']), $un, trim($_POST['email']),
                   $hash, $_POST['role'], trim($_POST['company'] ?? '')
               ]);
            addAuditLog('Added User', $un.' ('.$_POST['role'].')');
            $_SESSION['flash_msg']  = "User '$un' created.";
            $_SESSION['flash_type'] = 'success';
        }
    }

    if ($action === 'edit') {
        $uid = intval($_POST['id']);
        $db->prepare("UPDATE users SET name=?, email=?, role=?, company=?, is_active=? WHERE id=?")
           ->execute([
               trim($_POST['name']), trim($_POST['email']),
               $_POST['role'], trim($_POST['company'] ?? ''),
               intval($_POST['is_active']), $uid
           ]);
        if (!empty(trim($_POST['new_password']))) {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([password_hash(trim($_POST['new_password']), PASSWORD_BCRYPT), $uid]);
        }
        addAuditLog('Updated User', 'ID '.$uid);
        $_SESSION['flash_msg']  = "User updated.";
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'delete') {
        $uid = intval($_POST['id']);
        if ($uid === $_SESSION['user_id']) {
            $_SESSION['flash_msg']  = "You cannot delete your own account.";
            $_SESSION['flash_type'] = 'danger';
        } else {
            $db->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$uid]);
            addAuditLog('Deactivated User', 'ID '.$uid);
            $_SESSION['flash_msg']  = "User deactivated.";
            $_SESSION['flash_type'] = 'warning';
        }
    }

    header('Location: users.php');
    exit;
}

$users = $db->query("SELECT * FROM users ORDER BY is_active DESC, role, name")->fetchAll();
$roles = ['Admin','Manager','Technician','Helpdesk','Auditor'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-users-cog"></i> User Management</h2>
    <button class="btn btn-success" onclick="document.getElementById('addUserModal').classList.add('show')">
        <i class="fas fa-user-plus"></i> Add User
    </button>
</div>

<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg'] ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<div class="table-container">
    <table>
        <thead>
            <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Company</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr <?= !$u['is_active'] ? 'style="opacity:.55"' : '' ?>>
            <td><strong><?= sanitize($u['name']) ?></strong></td>
            <td><code><?= sanitize($u['username']) ?></code></td>
            <td><?= sanitize($u['email'] ?? '—') ?></td>
            <td>
                <span class="badge badge-<?= match($u['role']) {
                    'Admin' => 'danger', 'Manager' => 'primary',
                    'Technician' => 'success', 'Auditor' => 'warning',
                    default => 'info'
                } ?>">
                    <?= sanitize($u['role']) ?>
                </span>
            </td>
            <td><?= sanitize($u['company'] ?? '—') ?></td>
            <td>
                <span class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>">
                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </td>
            <td><?= $u['last_login'] ? date('M d, Y H:i', strtotime($u['last_login'])) : '—' ?></td>
            <td>
                <button class="action-btn" title="Edit"
                        onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                    <i class="fas fa-edit"></i>
                </button>
                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Deactivate user <?= sanitize($u['username']) ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button type="submit" class="action-btn" style="color:var(--danger)" title="Deactivate">
                        <i class="fas fa-user-slash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Role Reference -->
<div class="table-container">
    <h4 style="margin-bottom:14px">Role Permissions</h4>
    <table>
        <thead><tr><th>Role</th><th>Dashboard</th><th>Inventory</th><th>Edit Assets</th><th>Maintenance</th><th>Helpdesk</th><th>Users</th><th>Audit</th></tr></thead>
        <tbody>
            <tr><td><span class="badge badge-danger">Admin</span></td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
            <tr><td><span class="badge badge-primary">Manager</span></td><td>✅</td><td>✅</td><td>❌</td><td>✅</td><td>✅</td><td>❌</td><td>✅</td></tr>
            <tr><td><span class="badge badge-success">Technician</span></td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>❌</td><td>❌</td></tr>
            <tr><td><span class="badge badge-info">Helpdesk</span></td><td>✅</td><td>✅</td><td>❌</td><td>❌</td><td>✅</td><td>❌</td><td>❌</td></tr>
            <tr><td><span class="badge badge-warning">Auditor</span></td><td>✅</td><td>✅</td><td>❌</td><td>❌</td><td>❌</td><td>❌</td><td>✅</td></tr>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-content" style="max-width:540px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-user-plus"></i> Add New User</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group"><label>Full Name *</label><input name="name" required></div>
            <div class="form-group"><label>Username *</label><input name="username" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email"></div>
            <div class="form-group"><label>Password *</label><input type="password" name="password" required placeholder="Min. 6 characters"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" required>
                        <?php foreach ($roles as $r): ?><option value="<?= $r ?>"><?= $r ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Company / Branch</label><input name="company"></div>
            </div>
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Create User</button>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-content" style="max-width:540px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-edit"></i> Edit User</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editUserId">
            <div class="form-group"><label>Full Name</label><input name="name" id="editUserName"></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" id="editUserEmail"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="editUserRole">
                        <?php foreach ($roles as $r): ?><option value="<?= $r ?>"><?= $r ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="is_active" id="editUserActive">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Company / Branch</label><input name="company" id="editUserCompany"></div>
            <div class="form-group"><label>New Password <small>(leave blank to keep current)</small></label><input type="password" name="new_password" placeholder="Leave blank to keep current"></div>
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); }));
function openEditUserModal(user) {
    document.getElementById('editUserId').value      = user.id;
    document.getElementById('editUserName').value    = user.name;
    document.getElementById('editUserEmail').value   = user.email || '';
    document.getElementById('editUserRole').value    = user.role;
    document.getElementById('editUserActive').value  = user.is_active;
    document.getElementById('editUserCompany').value = user.company || '';
    document.getElementById('editUserModal').classList.add('show');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
