<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Maintenance';
$currentPage = 'maintenance';
$db = db();

// COMPLETE task 
if (isPost() && ($_POST['action'] ?? '') === 'complete') {
    $tid = intval($_POST['id'] ?? 0);
    if ($tid) {
        $db->prepare("UPDATE maintenance_tasks SET status='Completed', completed_date=CURDATE() WHERE id=?")
           ->execute([$tid]);
        $stmt = $db->prepare("SELECT mt.*, a.asset_tag FROM maintenance_tasks mt JOIN assets a ON mt.asset_id=a.id WHERE mt.id=?");
        $stmt->execute([$tid]);
        $task = $stmt->fetch();
        if ($task) {
            $db->prepare("INSERT INTO maintenance_log (asset_id, log_date, type, issue, technician, cost, status)
                          VALUES (?,CURDATE(),?,?,?,1500,'Completed')")
               ->execute([$task['asset_id'], 'Scheduled Maintenance', $task['task_name'], $task['assigned_to']]);
        }
        addAuditLog('Completed Maintenance', "Task ID: $tid");
        $_SESSION['flash_msg']  = 'Task marked as completed.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: maintenance.php'); exit;
}

// ADD task 
if (isPost() && ($_POST['action'] ?? '') === 'add') {
    $db->prepare("INSERT INTO maintenance_tasks (asset_id, task_name, priority, assigned_to, scheduled_date, notes, status)
                  VALUES (?,?,?,?,?,?,'Scheduled')")
       ->execute([
           intval($_POST['asset_id']),
           trim($_POST['task_name']),
           $_POST['priority'] ?? 'Medium',
           trim($_POST['assigned_to'] ?? ''),
           $_POST['scheduled_date'],
           trim($_POST['notes'] ?? '')
       ]);
    addAuditLog('Scheduled Maintenance', trim($_POST['task_name']));
    $_SESSION['flash_msg']  = 'Maintenance task scheduled.';
    $_SESSION['flash_type'] = 'success';
    header('Location: maintenance.php'); exit;
}

// EDIT task 
if (isPost() && ($_POST['action'] ?? '') === 'edit') {
    $tid = intval($_POST['id'] ?? 0);
    if ($tid) {
        $db->prepare("UPDATE maintenance_tasks
                      SET task_name=?, asset_id=?, priority=?, assigned_to=?,
                          scheduled_date=?, notes=?, status=?
                      WHERE id=?")
           ->execute([
               trim($_POST['task_name']),
               intval($_POST['asset_id']),
               $_POST['priority'] ?? 'Medium',
               trim($_POST['assigned_to'] ?? ''),
               $_POST['scheduled_date'],
               trim($_POST['notes'] ?? ''),
               $_POST['status'] ?? 'Scheduled',
               $tid
           ]);
        addAuditLog('Edited Maintenance Task', "Task ID: $tid");
        $_SESSION['flash_msg']  = 'Task updated successfully.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: maintenance.php'); exit;
}

// DELETE task 
if (isPost() && ($_POST['action'] ?? '') === 'delete') {
    $tid = intval($_POST['id'] ?? 0);
    if ($tid) {
        $db->prepare("DELETE FROM maintenance_tasks WHERE id=?")->execute([$tid]);
        addAuditLog('Deleted Maintenance Task', "Task ID: $tid");
        $_SESSION['flash_msg']  = 'Task deleted.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: maintenance.php'); exit;
}

// Filters 
$status   = $_GET['status']   ?? '';
$priority = $_GET['priority'] ?? '';
$search   = $_GET['search']   ?? '';

$where  = [];
$params = [];
if ($status)   { $where[] = "mt.status=?";   $params[] = $status; }
if ($priority) { $where[] = "mt.priority=?"; $params[] = $priority; }
if ($search) {
    $like = "%$search%";
    $where[] = "(mt.task_name LIKE ? OR a.asset_tag LIKE ? OR e.name LIKE ? OR mt.assigned_to LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT mt.*, a.asset_tag, a.id AS asset_db_id, e.name AS emp_name
    FROM   maintenance_tasks mt
    LEFT JOIN assets    a ON mt.asset_id    = a.id
    LEFT JOIN employees e ON a.employee_id  = e.id
    $whereSQL
    ORDER  BY mt.scheduled_date ASC
    LIMIT  200
");
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Stats
$scheduled  = $db->query("SELECT COUNT(*) FROM maintenance_tasks WHERE status='Scheduled'")->fetchColumn();
$inProgress = $db->query("SELECT COUNT(*) FROM maintenance_tasks WHERE status='In Progress'")->fetchColumn();
$completed  = $db->query("SELECT COUNT(*) FROM maintenance_tasks WHERE status='Completed'")->fetchColumn();
$totalCost  = $db->query("SELECT COALESCE(SUM(cost),0) FROM maintenance_tasks WHERE status='Completed'")->fetchColumn();

// Assets for dropdowns 
$allAssets = $db->query("
    SELECT a.id, a.asset_tag, e.name AS emp_name, d.name AS dept
    FROM   assets a
    LEFT JOIN employees   e ON a.employee_id   = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    ORDER  BY a.asset_tag
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Flash banner -->
<div id="flashMessage" style="display:none"
     data-message="<?= sanitize($_SESSION['flash_msg']  ?? '') ?>"
     data-type="<?= sanitize($_SESSION['flash_type'] ?? 'info') ?>"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>

<!-- Page heading -->
<div class="page-heading">
    <h2><i class="fas fa-tools"></i> Maintenance Schedule</h2>
    <?php if (hasRole('Admin', 'Technician')): ?>
    <button class="btn btn-success" id="openAddModal">
        <i class="fas fa-plus"></i> Schedule Task
    </button>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="stat-grid">
    <a class="stat-card" href="maintenance.php?status=Scheduled">
        <div class="stat-title">Scheduled</div>
        <div class="stat-value"><?= $scheduled ?></div>
    </a>
    <a class="stat-card" href="maintenance.php?status=In+Progress">
        <div class="stat-title">In Progress</div>
        <div class="stat-value"><?= $inProgress ?></div>
    </a>
    <a class="stat-card" href="maintenance.php?status=Completed">
        <div class="stat-title">Completed</div>
        <div class="stat-value"><?= $completed ?></div>
    </a>
    <div class="stat-card">
        <div class="stat-title">Total Cost</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($totalCost) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET">
<div class="filter-bar">
    <input class="filter-select" name="search"
           placeholder="Search task, asset, technician..."
           value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="status">
        <option value="">All Status</option>
        <?php foreach (['Scheduled','In Progress','Completed'] as $s): ?>
        <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <select class="filter-select" name="priority">
        <option value="">All Priority</option>
        <?php foreach (['Low','Medium','High'] as $p): ?>
        <option value="<?= $p ?>" <?= $priority===$p?'selected':'' ?>><?= $p ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="maintenance.php" class="btn btn-secondary">Clear</a>
    <button type="button" class="btn btn-info"
            onclick="exportTableToExcel('maintenanceTable','maintenance_export')">
        <i class="fas fa-file-excel"></i> Export
    </button>
</div>
</form>

<!-- Table -->
<div class="table-container">
    <p style="color:var(--text-muted);margin-bottom:14px;font-size:.9rem">
        Showing <?= count($tasks) ?> record(s)
    </p>
    <table id="maintenanceTable">
        <thead>
            <tr>
                <th>Task</th>
                <th>Asset</th>
                <th>Employee</th>
                <th>Date</th>
                <th>Assigned To</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Cost</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tasks as $t): ?>
        <tr>
            <td>
                <strong><?= sanitize($t['task_name']) ?></strong>
                <?php if (!empty($t['notes'])): ?>
                <br><small style="color:#aaa"><?= sanitize($t['notes']) ?></small>
                <?php endif; ?>
            </td>
            <td>
                <a href="asset_view.php?id=<?= $t['asset_id'] ?>">
                    <?= sanitize($t['asset_tag'] ?? '—') ?>
                </a>
            </td>
            <td><?= sanitize($t['emp_name'] ?? '—') ?></td>
            <td><?= sanitize($t['scheduled_date']) ?></td>
            <td>
                <?php if (!empty($t['assigned_to'])): ?>
                    <?= sanitize($t['assigned_to']) ?>
                <?php else: ?>
                    <span style="color:#bbb">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge badge-<?= $t['priority']==='High'?'danger':($t['priority']==='Medium'?'warning':'success') ?>">
                    <?= $t['priority'] ?>
                </span>
            </td>
            <td>
                <span class="status-badge status-<?= $t['status']==='Completed'?'active':($t['status']==='In Progress'?'maintenance':'scheduled') ?>">
                    <?= $t['status'] ?>
                </span>
                <?php if (!empty($t['completed_date'])): ?>
                <br><small><?= $t['completed_date'] ?></small>
                <?php endif; ?>
            </td>
            <td><?= peso($t['cost'] ?? 0) ?></td>
            <td style="white-space:nowrap">

                <?php if ($t['status'] !== 'Completed' && hasRole('Admin','Technician')): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button type="submit" class="action-btn" title="Mark Complete"
                            style="color:var(--success)">
                        <i class="fas fa-check-circle"></i>
                    </button>
                </form>
                <?php endif; ?>

                <a class="action-btn" href="asset_view.php?id=<?= $t['asset_id'] ?>"
                   title="View Asset">
                    <i class="fas fa-eye"></i>
                </a>

                <?php if (hasRole('Admin','Technician')): ?>
                <button type="button" class="action-btn js-open-edit" title="Edit Task"
                    style="color:#007bff"
                    data-id="<?= (int)$t['id'] ?>"
                    data-taskname="<?= htmlspecialchars($t['task_name'],           ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-assetid="<?= (int)$t['asset_id'] ?>"
                    data-priority="<?= htmlspecialchars($t['priority'],            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-assignedto="<?= htmlspecialchars($t['assigned_to'] ?? '', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-scheduleddate="<?= htmlspecialchars($t['scheduled_date'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-notes="<?= htmlspecialchars($t['notes'] ?? '',            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"
                    data-status="<?= htmlspecialchars($t['status'],                ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
                    <i class="fas fa-edit"></i>
                </button>
                <?php endif; ?>

                <?php if (hasRole('Admin')): ?>
                <button type="button" class="action-btn js-open-delete" title="Delete Task"
                    style="color:#dc3545"
                    data-id="<?= (int)$t['id'] ?>"
                    data-taskname="<?= htmlspecialchars($t['task_name'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>

            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($tasks)): ?>
        <tr>
            <td colspan="9">
                <div class="empty-state">
                    <i class="fas fa-tools"></i>
                    <p>No maintenance tasks found.</p>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- MODAL STYLES -->
<style>
.mt-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.mt-modal {
    background: #fff;
    border-radius: 12px;
    padding: 30px;
    width: 100%;
    max-width: 560px;
    max-height: 92vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 0 12px 48px rgba(0,0,0,.25);
    animation: mtSlide .18s ease;
}
.mt-modal-sm { max-width: 420px; }
@keyframes mtSlide { from { opacity:0; transform:translateY(-14px); } to { opacity:1; transform:none; } }
.mt-modal h3 { margin: 0 0 22px; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 9px; }
.mt-mclose {
    position: absolute; top: 14px; right: 15px;
    background: none; border: none; font-size: 22px;
    cursor: pointer; color: #aaa; padding: 4px 8px;
    border-radius: 5px; line-height: 1;
}
.mt-mclose:hover { background: #f0f0f0; color: #333; }
.mt-fg { margin-bottom: 14px; }
.mt-fg label {
    display: block; font-size: 12px; font-weight: 700;
    margin-bottom: 5px; color: #555;
    text-transform: uppercase; letter-spacing: .3px;
}
.mt-fg input,
.mt-fg select,
.mt-fg textarea {
    display: block; width: 100%;
    padding: 9px 11px;
    border: 1.5px solid #dde1e7;
    border-radius: 7px;
    font-size: 13px; color: #333;
    background: #fff;
    font-family: inherit;
    transition: border-color .15s, box-shadow .15s;
}
.mt-fg input:focus,
.mt-fg select:focus,
.mt-fg textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,.13);
}
.mt-fg textarea { resize: vertical; }
.mt-frow { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.mt-mfoot { display: flex; justify-content: flex-end; gap: 9px; margin-top: 22px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
.mt-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; transition:filter .15s; }
.mt-btn:hover { filter: brightness(.91); }
.mt-btn-green { background:#28a745; color:#fff; }
.mt-btn-blue  { background:#007bff; color:#fff; }
.mt-btn-red   { background:#dc3545; color:#fff; }
.mt-btn-gray  { background:#6c757d; color:#fff; }
.mt-confirm   { font-size:14px; color:#444; line-height:1.6; margin: 0 0 4px; }
@media(max-width:600px){ .mt-frow { grid-template-columns:1fr; } }
</style>


<!-- MODAL: Schedule / Add Task -->
<div class="mt-overlay" id="mt_mAdd" style="display:none">
  <div class="mt-modal">
    <button type="button" class="mt-mclose js-mt-close" data-target="mt_mAdd">&times;</button>
    <h3><i class="fas fa-plus-circle" style="color:#28a745"></i> Schedule Maintenance Task</h3>
    <form method="POST" action="maintenance.php" autocomplete="off">
      <input type="hidden" name="action" value="add">
      <div class="mt-fg">
        <label>Task Name <span style="color:red">*</span></label>
        <input type="text" name="task_name" required placeholder="e.g. Windows Updates, Disk Cleanup">
      </div>
      <div class="mt-fg">
        <label>Asset <span style="color:red">*</span></label>
        <select name="asset_id" required>
          <option value="">Select Asset</option>
          <?php foreach ($allAssets as $a): ?>
          <option value="<?= (int)$a['id'] ?>">
            <?= sanitize($a['asset_tag']) ?> — <?= sanitize($a['emp_name'] ?? '—') ?>
            <?php if (!empty($a['dept'])): ?>(<?= sanitize($a['dept']) ?>)<?php endif; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mt-frow">
        <div class="mt-fg">
          <label>Scheduled Date <span style="color:red">*</span></label>
          <input type="date" name="scheduled_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="mt-fg">
          <label>Priority</label>
          <select name="priority">
            <option value="Low">Low</option>
            <option value="Medium" selected>Medium</option>
            <option value="High">High</option>
          </select>
        </div>
      </div>
      <div class="mt-fg">
        <label>Assigned To</label>
        <!-- ← blank by default, no pre-filled name -->
        <input type="text" name="assigned_to" id="add_assignedto"
               placeholder="Enter technician name...">
      </div>
      <div class="mt-fg">
        <label>Notes</label>
        <textarea name="notes" rows="3" placeholder="Optional notes..."></textarea>
      </div>
      <div class="mt-mfoot">
        <button type="button" class="mt-btn mt-btn-gray js-mt-close" data-target="mt_mAdd">Cancel</button>
        <button type="submit" class="mt-btn mt-btn-green">
          <i class="fas fa-save"></i> Schedule Task
        </button>
      </div>
    </form>
  </div>
</div>


<!-- MODAL: Edit Task -->
<div class="mt-overlay" id="mt_mEdit" style="display:none">
  <div class="mt-modal">
    <button type="button" class="mt-mclose js-mt-close" data-target="mt_mEdit">&times;</button>
    <h3><i class="fas fa-edit" style="color:#007bff"></i> Edit Maintenance Task</h3>
    <form method="POST" action="maintenance.php" autocomplete="off">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="mt-fg">
        <label>Task Name <span style="color:red">*</span></label>
        <input type="text" name="task_name" id="edit_taskname" required>
      </div>
      <div class="mt-fg">
        <label>Asset <span style="color:red">*</span></label>
        <select name="asset_id" id="edit_assetid" required>
          <option value="">Select Asset</option>
          <?php foreach ($allAssets as $a): ?>
          <option value="<?= (int)$a['id'] ?>">
            <?= sanitize($a['asset_tag']) ?> — <?= sanitize($a['emp_name'] ?? '—') ?>
            <?php if (!empty($a['dept'])): ?>(<?= sanitize($a['dept']) ?>)<?php endif; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mt-frow">
        <div class="mt-fg">
          <label>Scheduled Date <span style="color:red">*</span></label>
          <input type="date" name="scheduled_date" id="edit_date" required>
        </div>
        <div class="mt-fg">
          <label>Priority</label>
          <select name="priority" id="edit_priority">
            <option value="Low">Low</option>
            <option value="Medium">Medium</option>
            <option value="High">High</option>
          </select>
        </div>
      </div>
      <div class="mt-frow">
        <div class="mt-fg">
          <label>Assigned To</label>
          <input type="text" name="assigned_to" id="edit_assignedto"
                 placeholder="Enter technician name...">
        </div>
        <div class="mt-fg">
          <label>Status</label>
          <select name="status" id="edit_status">
            <option value="Scheduled">Scheduled</option>
            <option value="In Progress">In Progress</option>
            <option value="Completed">Completed</option>
          </select>
        </div>
      </div>
      <div class="mt-fg">
        <label>Notes</label>
        <textarea name="notes" id="edit_notes" rows="3" placeholder="Optional notes..."></textarea>
      </div>
      <div class="mt-mfoot">
        <button type="button" class="mt-btn mt-btn-gray js-mt-close" data-target="mt_mEdit">Cancel</button>
        <button type="submit" class="mt-btn mt-btn-blue">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>


<!-- MODAL: Confirm Delete -->
<div class="mt-overlay" id="mt_mDelete" style="display:none">
  <div class="mt-modal mt-modal-sm">
    <button type="button" class="mt-mclose js-mt-close" data-target="mt_mDelete">&times;</button>
    <h3><i class="fas fa-exclamation-triangle" style="color:#dc3545"></i> Confirm Delete</h3>
    <p class="mt-confirm" id="mt_delMsg"></p>
    <form method="POST" action="maintenance.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="mt_delId">
      <div class="mt-mfoot">
        <button type="button" class="mt-btn mt-btn-gray js-mt-close" data-target="mt_mDelete">Cancel</button>
        <button type="submit" class="mt-btn mt-btn-red">
          <i class="fas fa-trash"></i> Yes, Delete
        </button>
      </div>
    </form>
  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

  function g(id) { return document.getElementById(id); }
  function openM(id)  { var el = g(id); if (el) el.style.display = 'flex'; }
  function closeM(id) { var el = g(id); if (el) el.style.display = 'none'; }

  /* close on backdrop click */
  document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('mt-overlay')) {
      e.target.style.display = 'none';
    }
  });

  /* close via data-target buttons */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-mt-close');
    if (btn) closeM(btn.getAttribute('data-target'));
  });

  /* ESC key */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.mt-overlay').forEach(function (o) {
        o.style.display = 'none';
      });
    }
  });

  /* Open Add modal — always reset the assigned_to field to blank */
  var btnAdd = g('openAddModal');
  if (btnAdd) {
    btnAdd.addEventListener('click', function () {
      var f = g('add_assignedto');
      if (f) f.value = '';          /* ← ensure blank every time it opens */
      openM('mt_mAdd');
    });
  }

  /* Open Edit modal */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-open-edit');
    if (!btn) return;

    g('edit_id').value         = btn.getAttribute('data-id')            || '';
    g('edit_taskname').value   = btn.getAttribute('data-taskname')      || '';
    g('edit_date').value       = btn.getAttribute('data-scheduleddate') || '';
    g('edit_assignedto').value = btn.getAttribute('data-assignedto')    || '';
    g('edit_notes').value      = btn.getAttribute('data-notes')         || '';

    /* asset dropdown */
    var assetSel = g('edit_assetid');
    var assetId  = btn.getAttribute('data-assetid') || '';
    for (var i = 0; i < assetSel.options.length; i++) {
      assetSel.options[i].selected = (assetSel.options[i].value === assetId);
    }

    /* priority dropdown */
    var priSel = g('edit_priority');
    var pri    = btn.getAttribute('data-priority') || 'Medium';
    for (var j = 0; j < priSel.options.length; j++) {
      priSel.options[j].selected = (priSel.options[j].value === pri);
    }

    /* status dropdown */
    var stSel  = g('edit_status');
    var status = btn.getAttribute('data-status') || 'Scheduled';
    for (var k = 0; k < stSel.options.length; k++) {
      stSel.options[k].selected = (stSel.options[k].value === status);
    }

    openM('mt_mEdit');
  });

  /* Open Delete modal */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-open-delete');
    if (!btn) return;
    var name = btn.getAttribute('data-taskname') || 'this task';
    g('mt_delMsg').textContent = 'Are you sure you want to permanently delete the task "' + name + '"? This cannot be undone.';
    g('mt_delId').value        = btn.getAttribute('data-id') || '';
    openM('mt_mDelete');
  });

});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>