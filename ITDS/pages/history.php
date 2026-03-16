<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'History & Filters';
$currentPage = 'history';
$db = db();

// ── DELETE handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $source = $_POST['source'] ?? '';   // 'maintenance_log' or 'maintenance_tasks' or 'supply_transactions'
    $id     = intval($_POST['id'] ?? 0);

    if ($id > 0) {
        if ($source === 'maintenance_log') {
            $db->prepare("DELETE FROM maintenance_log WHERE id = ?")->execute([$id]);
        } elseif ($source === 'maintenance_tasks') {
            $db->prepare("DELETE FROM maintenance_tasks WHERE id = ?")->execute([$id]);
        } elseif ($source === 'supply_transactions') {
            $db->prepare("DELETE FROM supply_transactions WHERE id = ?")->execute([$id]);
        }
        addAuditLog('Deleted History Record', "Source: $source, ID: $id");
    }

    // Rebuild query string without action
    $qs = http_build_query(array_diff_key($_GET, ['action' => 1]));
    header('Location: history.php' . ($qs ? "?$qs" : ''));
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$typeFilter   = $_GET['type']   ?? '';
$statusFilter = $_GET['status'] ?? '';
$month        = $_GET['month']  ?? '';
$year         = $_GET['year']   ?? date('Y');
$search       = $_GET['search'] ?? '';

// ── 1. Maintenance log ────────────────────────────────────────────────────────
$mlWhere = []; $mlParams = [];
if ($search) {
    $like = "%$search%";
    $mlWhere[]  = "(ml.issue LIKE ? OR ml.technician LIKE ? OR a.asset_tag LIKE ? OR e.name LIKE ?)";
    $mlParams   = array_merge($mlParams, [$like, $like, $like, $like]);
}
if ($month) { $mlWhere[] = "MONTH(ml.log_date)=?"; $mlParams[] = $month; }
if ($year)  { $mlWhere[] = "YEAR(ml.log_date)=?";  $mlParams[] = $year; }
$mlSQL = $mlWhere ? 'WHERE ' . implode(' AND ', $mlWhere) : '';

$q = $db->prepare("
    SELECT ml.id, ml.log_date AS date, 'Maintenance' AS type,
           a.asset_tag, e.name AS emp_name,
           ml.issue AS action, ml.technician, ml.cost, ml.status,
           ml.type AS details,
           'maintenance_log' AS source
    FROM   maintenance_log ml
    LEFT JOIN assets    a ON ml.asset_id    = a.id
    LEFT JOIN employees e ON a.employee_id  = e.id
    $mlSQL
    ORDER  BY ml.log_date DESC LIMIT 150
");
$q->execute($mlParams);
$mlRows = $q->fetchAll();

// ── 2. Completed maintenance tasks ─────────────────────────────────────────────
$mtWhere = ["mt.status='Completed'"]; $mtParams = [];
if ($search) {
    $like = "%$search%";
    $mtWhere[] = "(mt.task_name LIKE ? OR mt.assigned_to LIKE ? OR a.asset_tag LIKE ? OR e.name LIKE ?)";
    $mtParams  = array_merge($mtParams, [$like, $like, $like, $like]);
}
if ($month) { $mtWhere[] = "MONTH(mt.completed_date)=?"; $mtParams[] = $month; }
if ($year)  { $mtWhere[] = "YEAR(mt.completed_date)=?";  $mtParams[] = $year; }
$mtSQL = 'WHERE ' . implode(' AND ', $mtWhere);

$q = $db->prepare("
    SELECT mt.id, mt.completed_date AS date, 'Maintenance' AS type,
           a.asset_tag, e.name AS emp_name,
           mt.task_name AS action, mt.assigned_to AS technician,
           mt.cost, 'Completed' AS status, mt.priority AS details,
           'maintenance_tasks' AS source
    FROM   maintenance_tasks mt
    LEFT JOIN assets    a ON mt.asset_id    = a.id
    LEFT JOIN employees e ON a.employee_id  = e.id
    $mtSQL
    ORDER  BY mt.completed_date DESC LIMIT 150
");
$q->execute($mtParams);
$mtRows = $q->fetchAll();

// ── 3. Supply transactions ─────────────────────────────────────────────────────
$txWhere = []; $txParams = [];
if ($search) {
    $like = "%$search%";
    $txWhere[] = "(st.emp_name LIKE ? OR st.asset_tag LIKE ? OR st.reason LIKE ?)";
    $txParams  = array_merge($txParams, [$like, $like, $like]);
}
if ($month) { $txWhere[] = "MONTH(st.transaction_date)=?"; $txParams[] = $month; }
if ($year)  { $txWhere[] = "YEAR(st.transaction_date)=?";  $txParams[] = $year; }
$txSQL = $txWhere ? 'WHERE ' . implode(' AND ', $txWhere) : '';

$q = $db->prepare("
    SELECT st.id, st.transaction_date AS date, 'Replacement' AS type,
           st.asset_tag, st.emp_name,
           CONCAT(st.reason,' - ',IFNULL(st.old_item,'Item'),' replaced') AS action,
           st.technician, 0 AS cost, 'Completed' AS status, st.notes AS details,
           'supply_transactions' AS source
    FROM   supply_transactions st
    $txSQL
    ORDER  BY st.transaction_date DESC LIMIT 100
");
$q->execute($txParams);
$txRows = $q->fetchAll();

// ── Merge (NO audit rows) ──────────────────────────────────────────────────────
$all = array_merge($mlRows, $mtRows, $txRows);

// Apply type / status filters
if ($typeFilter)   { $all = array_values(array_filter($all, fn($r) => $r['type']   === $typeFilter)); }
if ($statusFilter) { $all = array_values(array_filter($all, fn($r) => $r['status'] === $statusFilter)); }

// Sort by date desc
usort($all, fn($a, $b) => strcmp($b['date'], $a['date']));

// Stats
$maintCount = count(array_filter($all, fn($r) => $r['type'] === 'Maintenance'));
$replCount  = count(array_filter($all, fn($r) => $r['type'] === 'Replacement'));
$totalCost  = array_sum(array_column($all, 'cost'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-history"></i> History &amp; Filters</h2>
    <button class="btn btn-info" onclick="exportTableToExcel('historyTable','history_export')">
        <i class="fas fa-file-excel"></i> Export
    </button>
</div>

<!-- Stats (no audit card) -->
<div class="stat-grid">
    <a class="stat-card" href="?type=Maintenance&year=<?= $year ?>">
        <div class="stat-title">Maintenance</div>
        <div class="stat-value"><?= $maintCount ?></div>
    </a>
    <a class="stat-card" href="?type=Replacement&year=<?= $year ?>">
        <div class="stat-title">Replacements</div>
        <div class="stat-value"><?= $replCount ?></div>
    </a>
    <div class="stat-card">
        <div class="stat-title">Total Records</div>
        <div class="stat-value"><?= count($all) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Total Cost</div>
        <div class="stat-value" style="font-size:1.3rem"><?= peso($totalCost) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET">
<div class="filter-bar">
    <input class="filter-select" name="search"
           placeholder="Search action, asset, employee..."
           value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="type">
        <option value="">All Types</option>
        <option value="Maintenance"  <?= $typeFilter==='Maintenance' ?'selected':'' ?>>Maintenance</option>
        <option value="Replacement"  <?= $typeFilter==='Replacement' ?'selected':'' ?>>Replacement</option>
    </select>
    <select class="filter-select" name="status">
        <option value="">All Status</option>
        <option value="Completed"   <?= $statusFilter==='Completed'  ?'selected':'' ?>>Completed</option>
        <option value="In Progress" <?= $statusFilter==='In Progress'?'selected':'' ?>>In Progress</option>
        <option value="Scheduled"   <?= $statusFilter==='Scheduled'  ?'selected':'' ?>>Scheduled</option>
    </select>
    <select class="filter-select" name="month">
        <option value="">All Months</option>
        <?php $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        foreach ($months as $i => $mn): ?>
        <option value="<?= $i+1 ?>" <?= $month==($i+1)?'selected':'' ?>><?= $mn ?></option>
        <?php endforeach; ?>
    </select>
    <select class="filter-select" name="year">
        <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
        <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="history.php" class="btn btn-secondary">Clear</a>
</div>
</form>

<!-- History Table -->
<div class="table-container">
    <p style="color:var(--text-muted);margin-bottom:14px;font-size:.9rem">
        Showing <?= count(array_slice($all, 0, 200)) ?> of <?= count($all) ?> record(s)
    </p>
    <table id="historyTable">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Asset</th>
                <th>Employee</th>
                <th>Action</th>
                <th>Technician / User</th>
                <th>Cost</th>
                <th>Status</th>
                <th>Details</th>
                <?php if (hasRole('Admin')): ?>
                <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($all, 0, 200) as $r): ?>
        <tr>
            <td><?= sanitize($r['date'] ?? '—') ?></td>
            <td>
                <span class="badge badge-<?= $r['type']==='Maintenance'?'warning':'success' ?>">
                    <?= sanitize($r['type']) ?>
                </span>
            </td>
            <td><?= sanitize($r['asset_tag'] ?? '—') ?></td>
            <td><?= sanitize($r['emp_name']  ?? '—') ?></td>
            <td><?= sanitize($r['action']    ?? '—') ?></td>
            <td><?= sanitize($r['technician']?? '—') ?></td>
            <td><?= peso($r['cost'] ?? 0) ?></td>
            <td>
                <span class="status-badge status-<?= $r['status']==='Completed'?'active':($r['status']==='In Progress'?'maintenance':'scheduled') ?>">
                    <?= sanitize($r['status'] ?? '—') ?>
                </span>
            </td>
            <td><?= sanitize(substr($r['details'] ?? '', 0, 60)) ?></td>
            <?php if (hasRole('Admin')): ?>
            <td>
                <button type="button"
                    class="action-btn js-hist-delete"
                    style="color:#dc3545"
                    title="Delete this record"
                    data-id="<?= (int)$r['id'] ?>"
                    data-source="<?= htmlspecialchars($r['source'], ENT_QUOTES) ?>"
                    data-label="<?= htmlspecialchars($r['action'] ?? 'this record', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($all)): ?>
        <tr>
            <td colspan="<?= hasRole('Admin') ? 10 : 9 ?>">
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No history records found for the selected filters.</p>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- MODAL STYLES -->
<style>
.hist-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.hist-modal {
    background: #fff;
    border-radius: 12px;
    padding: 30px;
    width: 100%;
    max-width: 420px;
    position: relative;
    box-shadow: 0 12px 48px rgba(0,0,0,.25);
    animation: histSlide .18s ease;
}
@keyframes histSlide { from { opacity:0; transform:translateY(-12px); } to { opacity:1; transform:none; } }
.hist-modal h3 { margin: 0 0 16px; font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 9px; }
.hist-mclose {
    position: absolute; top: 14px; right: 15px;
    background: none; border: none; font-size: 22px;
    cursor: pointer; color: #aaa; padding: 4px 8px;
    border-radius: 5px; line-height: 1;
}
.hist-mclose:hover { background: #f0f0f0; color: #333; }
.hist-confirm { font-size: 14px; color: #444; line-height: 1.6; margin: 0 0 6px; }
.hist-mfoot { display: flex; justify-content: flex-end; gap: 9px; margin-top: 22px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
.hist-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; transition:filter .15s; }
.hist-btn:hover { filter: brightness(.91); }
.hist-btn-red  { background:#dc3545; color:#fff; }
.hist-btn-gray { background:#6c757d; color:#fff; }
</style>

<!-- MODAL: Confirm Delete -->
<div class="hist-overlay" id="hist_mDelete" style="display:none">
  <div class="hist-modal">
    <button type="button" class="hist-mclose" id="hist_closeDelete">&times;</button>
    <h3><i class="fas fa-exclamation-triangle" style="color:#dc3545"></i> Confirm Delete</h3>
    <p class="hist-confirm" id="hist_delMsg"></p>
    <form method="POST" action="history.php">
      <?php
        // Pass current GET params so we return to the same filtered view
        foreach ($_GET as $k => $v):
          if ($k !== 'action'):
      ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
      <?php endif; endforeach; ?>
      <input type="hidden" name="action"  value="delete">
      <input type="hidden" name="source"  id="hist_delSource">
      <input type="hidden" name="id"      id="hist_delId">
      <div class="hist-mfoot">
        <button type="button" class="hist-btn hist-btn-gray" id="hist_cancelDelete">Cancel</button>
        <button type="submit" class="hist-btn hist-btn-red">
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

  /* close on backdrop */
  document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('hist-overlay')) {
      e.target.style.display = 'none';
    }
  });

  /* close buttons */
  g('hist_closeDelete') .addEventListener('click', function () { closeM('hist_mDelete'); });
  g('hist_cancelDelete').addEventListener('click', function () { closeM('hist_mDelete'); });

  /* ESC */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeM('hist_mDelete');
  });

  /* open delete modal */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-hist-delete');
    if (!btn) return;
    var label  = btn.getAttribute('data-label')  || 'this record';
    var source = btn.getAttribute('data-source') || '';
    var id     = btn.getAttribute('data-id')     || '';

    var sourceLabel = source === 'maintenance_log'      ? 'maintenance log'
                    : source === 'maintenance_tasks'    ? 'maintenance task'
                    : source === 'supply_transactions'  ? 'supply transaction'
                    : 'record';

    g('hist_delMsg').textContent =
      'Are you sure you want to permanently delete the ' + sourceLabel + ' "' + label + '"? This cannot be undone.';
    g('hist_delSource').value = source;
    g('hist_delId').value     = id;
    openM('hist_mDelete');
  });

});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>