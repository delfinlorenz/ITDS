<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Helpdesk Tickets';
$currentPage = 'helpdesk';
$db = db();

if (isPost()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $ticketId = 'TK' . str_pad($db->query("SELECT COUNT(*)+1 FROM tickets")->fetchColumn(), 5, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO tickets (id, title, description, priority, category, status, employee_name, asset_id)
                      VALUES (?,?,?,?,?,'Open',?,?)")
           ->execute([
               $ticketId,
               trim($_POST['title']),
               trim($_POST['description'] ?? ''),
               $_POST['priority'] ?? 'Medium',
               $_POST['category'] ?? 'Hardware',
               trim($_POST['employee_name'] ?? ''),
               intval($_POST['asset_id'] ?? 0) ?: null,
           ]);
        addAuditLog('Created Ticket', $ticketId.' - '.trim($_POST['title']));
        $_SESSION['flash_msg']  = "Ticket $ticketId created.";
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'resolve') {
        $tid = trim($_POST['id']);
        $db->prepare("UPDATE tickets SET status='Resolved', resolved_date=CURDATE(), resolved_by=?, resolution=? WHERE id=?")
           ->execute([$_SESSION['user']['name'] ?? 'Admin', trim($_POST['resolution'] ?? 'Issue resolved.'), $tid]);
        addAuditLog('Resolved Ticket', $tid);
        $_SESSION['flash_msg']  = "Ticket $tid resolved.";
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'assign') {
        $tid = trim($_POST['id']);
        $db->prepare("UPDATE tickets SET assigned_to=?, status='In Progress' WHERE id=?")
           ->execute([trim($_POST['assigned_to']), $tid]);
        addAuditLog('Assigned Ticket', $tid.' to '.trim($_POST['assigned_to']));
        $_SESSION['flash_msg']  = "Ticket assigned.";
        $_SESSION['flash_type'] = 'success';
    }

    if ($action === 'close') {
        $tid = trim($_POST['id']);
        $db->prepare("UPDATE tickets SET status='Closed' WHERE id=?")->execute([$tid]);
        addAuditLog('Closed Ticket', $tid);
        $_SESSION['flash_msg']  = "Ticket closed.";
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: helpdesk.php');
    exit;
}

$statusFilter   = $_GET['status']   ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$search         = $_GET['search']   ?? '';

$where = []; $params = [];
if ($statusFilter)   { $where[] = "t.status=?";   $params[] = $statusFilter; }
if ($priorityFilter) { $where[] = "t.priority=?"; $params[] = $priorityFilter; }
if ($search) {
    $like    = "%$search%";
    $where[] = "(t.title LIKE ? OR t.employee_name LIKE ? OR t.id LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like]);
}
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

$tickets = $db->prepare("SELECT t.*, a.asset_tag
    FROM tickets t
    LEFT JOIN assets a ON t.asset_id = a.id
    $whereSQL ORDER BY t.created_at DESC LIMIT 200");
$tickets->execute($params);
$tickets = $tickets->fetchAll();

$open       = $db->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open','Pending')")->fetchColumn();
$inProgress = $db->query("SELECT COUNT(*) FROM tickets WHERE status='In Progress'")->fetchColumn();
$resolved   = $db->query("SELECT COUNT(*) FROM tickets WHERE status='Resolved'")->fetchColumn();
$total      = $db->query("SELECT COUNT(*) FROM tickets")->fetchColumn();

$assets = $db->query("SELECT a.id, a.asset_tag, e.name AS emp_name FROM assets a LEFT JOIN employees e ON a.employee_id=e.id ORDER BY a.asset_tag LIMIT 300")->fetchAll();
$users  = $db->query("SELECT name FROM users WHERE is_active=1 AND role IN ('Admin','Technician','Helpdesk') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-headset"></i> Helpdesk Tickets</h2>
    <button class="btn btn-success" onclick="document.getElementById('createTicketModal').classList.add('show')">
        <i class="fas fa-plus"></i> New Ticket
    </button>
</div>

<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg'] ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<div class="stat-grid">
    <a class="stat-card" href="helpdesk.php?status=Open"><div class="stat-title">Open</div><div class="stat-value" style="color:var(--warning)"><?= $open ?></div></a>
    <a class="stat-card" href="helpdesk.php?status=In+Progress"><div class="stat-title">In Progress</div><div class="stat-value" style="color:var(--primary)"><?= $inProgress ?></div></a>
    <a class="stat-card" href="helpdesk.php?status=Resolved"><div class="stat-title">Resolved</div><div class="stat-value" style="color:var(--success)"><?= $resolved ?></div></a>
    <div class="stat-card"><div class="stat-title">Total Tickets</div><div class="stat-value"><?= $total ?></div></div>
</div>

<form method="GET">
<div class="filter-bar">
    <input class="filter-select" name="search" placeholder="Search ticket ID, title, employee..." value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="status">
        <option value="">All Status</option>
        <?php foreach (['Open','Pending','In Progress','Resolved','Closed'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <select class="filter-select" name="priority">
        <option value="">All Priority</option>
        <?php foreach (['Low','Medium','High','Critical'] as $p): ?>
        <option value="<?= $p ?>" <?= $priorityFilter===$p?'selected':'' ?>><?= $p ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="helpdesk.php" class="btn btn-secondary">Clear</a>
</div>
</form>

<div class="table-container">
    <table>
        <thead>
            <tr><th>ID</th><th>Title</th><th>Employee</th><th>Asset</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned To</th><th>Created</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr>
            <td><strong><?= sanitize($t['id']) ?></strong></td>
            <td><?= sanitize($t['title']) ?></td>
            <td><?= sanitize($t['employee_name'] ?? '—') ?></td>
            <td><?= sanitize($t['asset_tag'] ?? '—') ?></td>
            <td><?= sanitize($t['category'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $t['priority']==='High'||$t['priority']==='Critical'?'danger':($t['priority']==='Medium'?'warning':'info') ?>"><?= sanitize($t['priority']) ?></span></td>
            <td><span class="badge badge-<?= $t['status']==='Resolved'||$t['status']==='Closed'?'success':($t['status']==='In Progress'?'warning':'info') ?>"><?= sanitize($t['status']) ?></span></td>
            <td><?= sanitize($t['assigned_to'] ?? '—') ?></td>
            <td><?= date('M d, Y', strtotime($t['created_at'])) ?></td>
            <td>
                <?php if (!in_array($t['status'], ['Resolved','Closed'])): ?>
                <?php if (hasRole('Admin','Technician','Helpdesk')): ?>
                <button class="action-btn" style="color:var(--primary)" title="Assign"
                        onclick="openAssign('<?= sanitize($t['id']) ?>')"><i class="fas fa-user-check"></i></button>
                <button class="action-btn" style="color:var(--success)" title="Resolve"
                        onclick="openResolve('<?= sanitize($t['id']) ?>')"><i class="fas fa-check"></i></button>
                <?php endif; ?>
                <?php else: ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="id" value="<?= sanitize($t['id']) ?>">
                    <button type="submit" class="action-btn" title="Close"><i class="fas fa-times-circle"></i></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tickets)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="fas fa-headset"></i><p>No tickets found. Click <strong>New Ticket</strong> to create one.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Create Ticket Modal -->
<div class="modal" id="createTicketModal">
    <div class="modal-content" style="max-width:580px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-plus"></i> New Ticket</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-group"><label>Title / Issue *</label><input name="title" required placeholder="Brief description of the issue"></div>
            <div class="form-group"><label>Employee Name *</label><input name="employee_name" required placeholder="Who is reporting this?"></div>
            <div class="form-group">
                <label>Related Asset</label>
                <select name="asset_id">
                    <option value="">None / Not asset-related</option>
                    <?php foreach ($assets as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= sanitize($a['asset_tag']) ?> — <?= sanitize($a['emp_name'] ?? '—') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category">
                        <?php foreach (['Hardware','Software','Network','Account','Printer','Other'] as $c): ?>
                        <option value="<?= $c ?>"><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach (['Low','Medium','High','Critical'] as $p): ?>
                        <option value="<?= $p ?>" <?= $p==='Medium'?'selected':'' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Description</label><textarea name="description" rows="3" placeholder="Detailed description..."></textarea></div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Submit Ticket</button>
                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal" id="assignModal">
    <div class="modal-content" style="max-width:420px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-user-check"></i> Assign Ticket</h2>
        <form method="POST">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="id" id="assignTicketId">
            <div class="form-group">
                <label>Assign To *</label>
                <select name="assigned_to" required>
                    <option value="">Select technician</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= sanitize($u) ?>"><?= sanitize($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Assign</button>
        </form>
    </div>
</div>

<!-- Resolve Modal -->
<div class="modal" id="resolveModal">
    <div class="modal-content" style="max-width:420px">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h2 style="margin-bottom:18px"><i class="fas fa-check-circle"></i> Resolve Ticket</h2>
        <form method="POST">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="id" id="resolveTicketId">
            <div class="form-group"><label>Resolution Notes *</label><textarea name="resolution" rows="3" required placeholder="What was done to resolve the issue?"></textarea></div>
            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Mark Resolved</button>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { if (e.target===m) m.classList.remove('show'); }));
function openAssign(id) {
    document.getElementById('assignTicketId').value = id;
    document.getElementById('assignModal').classList.add('show');
}
function openResolve(id) {
    document.getElementById('resolveTicketId').value = id;
    document.getElementById('resolveModal').classList.add('show');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
