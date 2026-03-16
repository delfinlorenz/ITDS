<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Notifications';
$currentPage = 'notifications';
$db = db();

if (isPost()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")
           ->execute([intval($_POST['id']), $_SESSION['user_id']]);
    }
    if ($action === 'mark_all_read') {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
           ->execute([$_SESSION['user_id']]);
    }
    header('Location: notifications.php');
    exit;
}

$notifications = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
$notifications->execute([$_SESSION['user_id']]);
$notifications = $notifications->fetchAll();

$unread = count(array_filter($notifications, fn($n) => !$n['is_read']));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-bell"></i> Notifications <span class="badge badge-danger"><?= $unread ?> unread</span></h2>
    <?php if ($unread): ?>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-secondary"><i class="fas fa-check-double"></i> Mark All Read</button>
    </form>
    <?php endif; ?>
</div>

<div class="table-container">
<?php if (empty($notifications)): ?>
    <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No notifications yet.</p></div>
<?php else: ?>
    <?php foreach ($notifications as $n): ?>
    <div style="display:flex;align-items:flex-start;gap:14px;padding:14px;border-bottom:1px solid var(--border);<?= !$n['is_read'] ? 'background:var(--bg-tertiary);' : '' ?>">
        <div style="width:38px;height:38px;border-radius:50%;background:var(--<?= $n['type']==='warning'?'warning':($n['type']==='danger'?'danger':'primary') ?>);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-<?= $n['type']==='warning'?'exclamation-triangle':($n['type']==='danger'?'times-circle':'info-circle') ?>" style="color:#fff"></i>
        </div>
        <div style="flex:1">
            <div style="font-weight:<?= !$n['is_read'] ? '600' : '400' ?>"><?= sanitize($n['message']) ?></div>
            <div style="font-size:.8rem;color:var(--text-muted);margin-top:4px"><?= date('M d, Y H:i', strtotime($n['created_at'])) ?></div>
        </div>
        <?php if (!$n['is_read']): ?>
        <form method="POST">
            <input type="hidden" name="action" value="mark_read">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button type="submit" class="action-btn" title="Mark as read"><i class="fas fa-check"></i></button>
        </form>
        <?php else: ?>
        <span style="font-size:.75rem;color:var(--text-muted)">Read</span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
