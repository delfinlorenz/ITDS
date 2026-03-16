<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'My Profile';
$currentPage = 'profile';
$db = db();

if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $db->prepare("UPDATE users SET name=?, email=?, company=? WHERE id=?")
           ->execute([
               trim($_POST['name']),
               trim($_POST['email']    ?? ''),
               trim($_POST['company']  ?? ''),
               $_SESSION['user_id']
           ]);
        // Keep session in sync
        $_SESSION['user']['name'] = trim($_POST['name']);
        $_SESSION['flash_msg']    = 'Profile updated.';
        $_SESSION['flash_type']   = 'success';
        addAuditLog('Updated Profile', 'Self');
    }

    if ($action === 'change_password') {
        $chk = $db->prepare("SELECT password FROM users WHERE id=?");
        $chk->execute([$_SESSION['user_id']]);
        $chk = $chk->fetch();

        if (!$chk || !password_verify(trim($_POST['current_password']), $chk['password'])) {
            $_SESSION['flash_msg']  = 'Current password is incorrect.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (trim($_POST['new_password']) !== trim($_POST['confirm_password'])) {
            $_SESSION['flash_msg']  = 'New passwords do not match.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (strlen(trim($_POST['new_password'])) < 6) {
            $_SESSION['flash_msg']  = 'Password must be at least 6 characters.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([password_hash(trim($_POST['new_password']), PASSWORD_BCRYPT), $_SESSION['user_id']]);
            $_SESSION['flash_msg']  = 'Password changed successfully.';
            $_SESSION['flash_type'] = 'success';
            addAuditLog('Changed Password', 'Self');
        }
    }

    header('Location: profile.php');
    exit;
}

// Fetch current user row — explicit columns so created_at & last_login are always included
$stmt = $db->prepare("
    SELECT id, name, username, email, role, company, is_active,
           created_at, last_login
    FROM users
    WHERE id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Safety fallback — if explicit columns failed (e.g. column name differs), try SELECT *
if (!$user) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Audit log — match by user_id (most reliable) OR fall back to name match
$stmt2 = $db->prepare("SELECT * FROM audit_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
$stmt2->execute([$_SESSION['user_id']]);
$myLogs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// If audit_log has no user_id column, fall back to matching by name
if (empty($myLogs)) {
    $userName = $user['name'] ?? '';
    $stmt2 = $db->prepare("SELECT * FROM audit_log WHERE user_name = ? ORDER BY created_at DESC LIMIT 30");
    $stmt2->execute([$userName]);
    $myLogs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// ── TEMP DEBUG — remove after confirming columns ──────────────────────────────
// Uncomment the line below, load the page, note the column names, then re-comment it
// die('<pre>USER ROW KEYS: '.implode(', ', array_keys($user ?? [])).'\n\nFULL ROW:\n'.print_r($user,true).'</pre>');
// ───────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-user-circle"></i> My Profile</h2>
</div>

<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg']  ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">

<!-- ── Profile Information ─────────────────────────────────────────────────── -->
<div class="table-container">
    <h4 style="margin-bottom:18px"><i class="fas fa-id-card"></i> Profile Information</h4>

    <!-- Avatar + name -->
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--primary);
                    display:flex;align-items:center;justify-content:center;
                    font-size:2rem;color:#fff;font-weight:700;flex-shrink:0">
            <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
        </div>
        <div>
            <div style="font-size:1.3rem;font-weight:700"><?= sanitize($user['name'] ?? '') ?></div>
            <div style="margin:4px 0">
                <?php
                $roleColor = match($user['role'] ?? '') {
                    'Admin'      => 'danger',
                    'Manager'    => 'primary',
                    'Technician' => 'success',
                    'Auditor'    => 'warning',
                    default      => 'info'
                };
                ?>
                <span class="badge badge-<?= $roleColor ?>"><?= sanitize($user['role'] ?? '') ?></span>
            </div>
            <div style="color:var(--text-muted);font-size:.85rem"><?= sanitize($user['username'] ?? '') ?></div>
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="update_profile">
        <div class="form-group">
            <label>Full Name</label>
            <input name="name" value="<?= sanitize($user['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= sanitize($user['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Company / Branch</label>
            <input name="company" value="<?= sanitize($user['company'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Username</label>
            <input value="<?= sanitize($user['username'] ?? '') ?>" disabled style="opacity:.6">
            <small style="color:var(--text-muted)">Username cannot be changed.</small>
        </div>
        <div class="form-group">
            <label>Role</label>
            <input value="<?= sanitize($user['role'] ?? '') ?>" disabled style="opacity:.6">
        </div>
        <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Update Profile
        </button>
    </form>
</div>

<!-- ── Change Password + Account Stats ────────────────────────────────────── -->
<div>
    <div class="table-container" style="margin-bottom:22px">
        <h4 style="margin-bottom:18px"><i class="fas fa-lock"></i> Change Password</h4>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required placeholder="Min. 6 characters">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-key"></i> Change Password
            </button>
        </form>
    </div>

    <!-- Account Stats -->
    <div class="table-container">
        <h4 style="margin-bottom:18px"><i class="fas fa-chart-bar"></i> Account Stats</h4>
        <div class="detail-grid" style="grid-template-columns:1fr 1fr">

            <div class="detail-item">
                <span class="detail-label">Member Since</span>
                <span><?php
                    // Try multiple possible column names
                    $caRaw = $user['created_at']
                          ?? $user['date_created']
                          ?? $user['registered_at']
                          ?? null;
                    $ca = ($caRaw && $caRaw !== '0000-00-00 00:00:00') ? strtotime($caRaw) : false;
                    echo ($ca && $ca > 0) ? date('M d, Y', $ca) : '—';
                ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Last Login</span>
                <span><?php
                    // Try multiple possible column names
                    $llRaw = $user['last_login']
                          ?? $user['last_login_at']
                          ?? $user['login_at']
                          ?? null;
                    $ll = ($llRaw && $llRaw !== '0000-00-00 00:00:00') ? strtotime($llRaw) : false;
                    echo ($ll && $ll > 0) ? date('M d, Y H:i', $ll) : '—';
                ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Total Actions</span>
                <span><?php
                    // Count all audit entries for this user
                    $tc = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ?");
                    $tc->execute([$_SESSION['user_id']]);
                    $totalActions = intval($tc->fetch(PDO::FETCH_NUM)[0] ?? 0);
                    // Fallback: count by name if user_id column doesn't exist
                    if ($totalActions === 0 && !empty($user['name'])) {
                        $tc2 = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE user_name = ?");
                        $tc2->execute([$user['name']]);
                        $totalActions = intval($tc2->fetch(PDO::FETCH_NUM)[0] ?? 0);
                    }
                    echo $totalActions;
                ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Status</span>
                <span class="badge badge-success">Active</span>
            </div>

        </div>
    </div>
</div>

</div><!-- /grid -->

<!-- ── Recent Activity ─────────────────────────────────────────────────────── -->
<div class="table-container" style="margin-top:22px">
    <h4 style="margin-bottom:16px"><i class="fas fa-history"></i> My Recent Activity</h4>
    <table>
        <thead>
            <tr><th>Timestamp</th><th>Action</th><th>Details</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($myLogs as $log): ?>
        <tr>
            <td><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></td>
            <td><?= sanitize($log['action']) ?></td>
            <td><?= sanitize($log['details'] ?? '') ?></td>
            <td><small><?= sanitize($log['ip_address'] ?? '—') ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($myLogs)): ?>
        <tr>
            <td colspan="4">
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No activity yet.</p>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.detail-grid  { display:grid; gap:10px; }
.detail-item  { display:flex; flex-direction:column; gap:3px; padding:10px; background:var(--bg-tertiary); border-radius:6px; }
.detail-label { font-size:.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>