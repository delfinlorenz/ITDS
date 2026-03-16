<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Software & Licenses';
$currentPage = 'software';
$db = db();

// ── FIX: Reset all used_seats to match actual installed_software count ─────────
// Run once automatically on every page load (cheap query, safe to run always)
$db->query("
    UPDATE software_licenses sl
    SET sl.used_seats = (
        SELECT COUNT(*) FROM installed_software ins WHERE ins.name = sl.name
    )
");

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD LICENSE
    if ($action === 'add_license') {
        $db->prepare("
            INSERT INTO software_licenses
                (name, vendor, total_seats, used_seats, cost_per_seat, expiration_date, notes)
            VALUES (?, ?, ?, 0, ?, ?, ?)
        ")->execute([
            trim($_POST['name']),
            trim($_POST['vendor']),
            max(1, intval($_POST['total_seats'])),
            max(0, floatval($_POST['cost_per_seat'])),
            !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null,
            trim($_POST['notes'] ?? '')
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'License added successfully.'];
        header('Location: software.php'); exit;
    }

    // EDIT LICENSE
    if ($action === 'edit_license') {
        $licId    = intval($_POST['license_id']);
        $newTotal = max(1, intval($_POST['total_seats']));
        // Get current used count
        $st = $db->prepare("SELECT used_seats FROM software_licenses WHERE id = ?");
        $st->execute([$licId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if ($cur && $newTotal < (int)$cur['used_seats']) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Total seats cannot be less than currently used seats (' . $cur['used_seats'] . ').'];
            header('Location: software.php'); exit;
        }
        $db->prepare("
            UPDATE software_licenses
            SET name=?, vendor=?, total_seats=?, cost_per_seat=?, expiration_date=?, notes=?
            WHERE id=?
        ")->execute([
            trim($_POST['name']),
            trim($_POST['vendor']),
            $newTotal,
            max(0, floatval($_POST['cost_per_seat'])),
            !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null,
            trim($_POST['notes'] ?? ''),
            $licId
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'License updated successfully.'];
        header('Location: software.php'); exit;
    }

    // DELETE LICENSE
    if ($action === 'delete_license') {
        $licId = intval($_POST['license_id']);
        $st = $db->prepare("SELECT used_seats, name FROM software_licenses WHERE id = ?");
        $st->execute([$licId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['used_seats'] === 0) {
            $db->prepare("DELETE FROM software_licenses WHERE id = ?")->execute([$licId]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'License "' . $row['name'] . '" deleted.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Cannot delete: this license still has ' . ($row['used_seats'] ?? 0) . ' assigned seat(s).'];
        }
        header('Location: software.php'); exit;
    }

    // ASSIGN SEAT
    if ($action === 'assign_seat') {
        $licId = intval($_POST['license_id']);
        $st = $db->prepare("SELECT * FROM software_licenses WHERE id = ?");
        $st->execute([$licId]);
        $lic = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lic) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'License not found.'];
            header('Location: software.php'); exit;
        }
        if ((int)$lic['used_seats'] >= (int)$lic['total_seats']) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'No available seats for "' . $lic['name'] . '".'];
            header('Location: software.php'); exit;
        }
        // Resolve asset_id from asset_tag
        $assetId = null;
        $assetTag = trim($_POST['asset_tag'] ?? '');
        if ($assetTag !== '') {
            $a = $db->prepare("SELECT id FROM assets WHERE asset_tag = ?");
            $a->execute([$assetTag]);
            $ar = $a->fetch(PDO::FETCH_ASSOC);
            $assetId = $ar ? (int)$ar['id'] : null;
        }
        $db->prepare("
            INSERT INTO installed_software (asset_id, name, version, license_key, install_date)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $assetId,
            $lic['name'],
            trim($_POST['version'] ?? ''),
            trim($_POST['license_key'] ?? ''),
            !empty($_POST['install_date']) ? $_POST['install_date'] : date('Y-m-d')
        ]);
        $db->prepare("UPDATE software_licenses SET used_seats = used_seats + 1 WHERE id = ?")
           ->execute([$licId]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Seat assigned for "' . $lic['name'] . '".'];
        header('Location: software.php'); exit;
    }

    // EDIT INSTALLED
    if ($action === 'edit_installed') {
        $instId   = intval($_POST['installed_id']);
        $assetId  = null;
        $assetTag = trim($_POST['asset_tag'] ?? '');
        if ($assetTag !== '') {
            $a = $db->prepare("SELECT id FROM assets WHERE asset_tag = ?");
            $a->execute([$assetTag]);
            $ar = $a->fetch(PDO::FETCH_ASSOC);
            $assetId = $ar ? (int)$ar['id'] : null;
        }
        $db->prepare("
            UPDATE installed_software
            SET asset_id=?, version=?, license_key=?, install_date=?
            WHERE id=?
        ")->execute([
            $assetId,
            trim($_POST['version'] ?? ''),
            trim($_POST['license_key'] ?? ''),
            !empty($_POST['install_date']) ? $_POST['install_date'] : null,
            $instId
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Installed software record updated.'];
        header('Location: software.php'); exit;
    }

    // DELETE INSTALLED
    if ($action === 'delete_installed') {
        $instId = intval($_POST['installed_id']);
        $st = $db->prepare("SELECT name FROM installed_software WHERE id = ?");
        $st->execute([$instId]);
        $inst = $st->fetch(PDO::FETCH_ASSOC);
        if ($inst) {
            $db->prepare("DELETE FROM installed_software WHERE id = ?")->execute([$instId]);
            // Recalculate used_seats
            $db->prepare("
                UPDATE software_licenses SET used_seats = (
                    SELECT COUNT(*) FROM installed_software WHERE name = ?
                ) WHERE name = ?
            ")->execute([$inst['name'], $inst['name']]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Record removed and seat freed.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Record not found.'];
        }
        header('Location: software.php'); exit;
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$licenses  = $db->query("SELECT * FROM software_licenses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$installed = $db->query("
    SELECT s.*, a.asset_tag, e.name AS employee_name
    FROM   installed_software s
    LEFT JOIN assets    a ON s.asset_id    = a.id
    LEFT JOIN employees e ON a.employee_id = e.id
    ORDER  BY s.name
    LIMIT  500
")->fetchAll(PDO::FETCH_ASSOC);

$totalSeats = 0; $usedSeats = 0; $totalCost = 0;
foreach ($licenses as $l) {
    $totalSeats += (int)$l['total_seats'];
    $usedSeats  += (int)$l['used_seats'];
    $totalCost  += (int)$l['total_seats'] * (float)$l['cost_per_seat'];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
/* ─── Scoped to .swp so nothing leaks into your global styles ─── */
.swp { padding: 24px; }
.swp *, .swp *::before, .swp *::after { box-sizing: border-box; }

/* topbar */
.swp-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; }
.swp-topbar h2 { margin:0; font-size:20px; font-weight:700; color:#1a1a2e; display:flex; align-items:center; gap:10px; }

/* flash */
.swp-flash { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:7px; margin-bottom:18px; font-size:13px; font-weight:500; }
.swp-flash-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.swp-flash-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* stats grid */
.swp-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
.swp-stat  { background:#fff; padding:18px 20px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.07); }
.swp-stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#999; font-weight:600; margin-bottom:6px; }
.swp-stat-val   { font-size:28px; font-weight:800; color:#1a1a2e; line-height:1; }

/* cards */
.swp-card { background:#fff; border-radius:10px; padding:20px; margin-bottom:22px; box-shadow:0 2px 8px rgba(0,0,0,.07); }
.swp-card-title { font-size:14px; font-weight:700; margin:0 0 16px; display:flex; align-items:center; gap:8px; color:#1a1a2e; }

/* table */
.swp-tbl-scroll { overflow-x:auto; }
.swp-tbl { width:100%; border-collapse:collapse; font-size:13px; min-width:800px; }
.swp-tbl th { background:#f7f8fa; padding:10px 13px; text-align:left; border-bottom:2px solid #e8eaed; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#777; font-weight:700; white-space:nowrap; }
.swp-tbl td { padding:11px 13px; border-bottom:1px solid #f2f2f2; vertical-align:middle; color:#333; }
.swp-tbl tbody tr:last-child td { border-bottom:none; }
.swp-tbl tbody tr:hover td { background:#fafbfd; }

/* progress bar */
.swp-prog-wrap { display:flex; align-items:center; gap:7px; white-space:nowrap; }
.swp-prog      { background:#e9ecef; height:6px; width:64px; border-radius:3px; overflow:hidden; flex-shrink:0; }
.swp-bar       { height:100%; border-radius:3px; }
.swp-bar-ok    { background:#28a745; }
.swp-bar-warn  { background:#ffc107; }
.swp-bar-full  { background:#dc3545; }

/* badges */
.swp-badge         { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.swp-badge-success { background:#d4edda; color:#155724; }
.swp-badge-warning { background:#fff3cd; color:#856404; }
.swp-badge-danger  { background:#f8d7da; color:#721c24; }

/* main buttons */
.swp-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border:none; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; transition:filter .15s, transform .1s; line-height:1; }
.swp-btn:hover  { filter:brightness(.91); }
.swp-btn:active { transform:scale(.97); }
.swp-btn-green  { background:#28a745; color:#fff; }
.swp-btn-blue   { background:#007bff; color:#fff; }
.swp-btn-red    { background:#dc3545; color:#fff; }
.swp-btn-gray   { background:#6c757d; color:#fff; }

/* icon buttons */
.swp-ib { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:7px; border:none; cursor:pointer; background:transparent; font-size:14px; transition:background .15s, color .15s; }
.swp-ib-green { color:#28a745; }
.swp-ib-green:hover { background:#d4edda; color:#155724; }
.swp-ib-blue  { color:#007bff; }
.swp-ib-blue:hover  { background:#cce5ff; color:#004085; }
.swp-ib-red   { color:#dc3545; }
.swp-ib-red:hover   { background:#f8d7da; color:#721c24; }
.swp-ib-off   { color:#ccc !important; cursor:not-allowed !important; }
.swp-ib-off:hover { background:transparent !important; }

/* ─── MODALS: use style.display so NO external CSS can override ─── */
.swp-overlay {
    /* always starts hidden via inline style="display:none" in HTML */
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,.6);
    z-index: 999999;
    display: flex;          /* value toggled by JS */
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.swp-modal {
    background: #fff;
    border-radius: 12px;
    padding: 30px;
    width: 100%;
    max-width: 500px;
    max-height: 92vh;
    overflow-y: auto;
    position: relative;
    box-shadow: 0 12px 48px rgba(0,0,0,.28);
    animation: swpSlideIn .18s ease;
}
@keyframes swpSlideIn { from { opacity:0; transform:translateY(-16px); } to { opacity:1; transform:none; } }
.swp-modal-sm { max-width: 430px; }
.swp-modal h3 { margin:0 0 22px; font-size:17px; font-weight:700; display:flex; align-items:center; gap:9px; color:#1a1a2e; }
.swp-mclose { position:absolute; top:15px; right:16px; background:none; border:none; font-size:22px; cursor:pointer; color:#aaa; padding:4px 7px; border-radius:5px; line-height:1; }
.swp-mclose:hover { background:#f0f0f0; color:#333; }

/* form elements inside modal */
.swp-fg { margin-bottom:14px; }
.swp-fg label { display:block; font-size:12px; font-weight:700; margin-bottom:5px; color:#555; text-transform:uppercase; letter-spacing:.3px; }
.swp-fg input,
.swp-fg textarea,
.swp-fg select {
    display: block;
    width: 100%;
    padding: 9px 11px;
    border: 1.5px solid #dde1e7;
    border-radius: 7px;
    font-size: 13px;
    color: #333;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
}
.swp-fg input:focus,
.swp-fg textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,.13);
}
.swp-fg textarea { resize:vertical; }
.swp-frow { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.swp-mfoot { display:flex; justify-content:flex-end; gap:9px; margin-top:22px; padding-top:18px; border-top:1px solid #f0f0f0; }
.swp-confirm-msg { font-size:14px; color:#444; line-height:1.6; margin:0 0 4px; }

/* misc */
.swp-tbl code { background:#f0f0f0; padding:2px 6px; border-radius:4px; font-size:11px; font-family:monospace; }
.swp-empty { text-align:center; padding:36px; color:#bbb; font-size:14px; }

@media(max-width:640px) {
    .swp-stats { grid-template-columns:1fr 1fr; }
    .swp-frow  { grid-template-columns:1fr; }
}
</style>

<div class="swp">

  <!-- Topbar -->
  <div class="swp-topbar">
    <h2>
      <i class="fas fa-compact-disc" style="color:#007bff"></i>
      Software &amp; Licenses
    </h2>
    <?php if (hasRole('Admin')): ?>
    <button class="swp-btn swp-btn-green" id="sw_openAdd">
      <i class="fas fa-plus"></i> Add License
    </button>
    <?php endif; ?>
  </div>

  <!-- Flash message -->
  <?php if ($flash): ?>
  <div class="swp-flash swp-flash-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
    <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="swp-stats">
    <div class="swp-stat">
      <div class="swp-stat-label">Total Licenses</div>
      <div class="swp-stat-val"><?= count($licenses) ?></div>
    </div>
    <div class="swp-stat">
      <div class="swp-stat-label">Total Seats</div>
      <div class="swp-stat-val"><?= $totalSeats ?></div>
    </div>
    <div class="swp-stat">
      <div class="swp-stat-label">Used Seats</div>
      <div class="swp-stat-val"><?= $usedSeats ?></div>
    </div>
    <div class="swp-stat">
      <div class="swp-stat-label">Total Cost</div>
      <div class="swp-stat-val"><?= peso($totalCost) ?></div>
    </div>
  </div>

  <!-- ─── Software Licenses Table ──────────────────────────────────────────── -->
  <div class="swp-card">
    <div class="swp-card-title">
      <i class="fas fa-key" style="color:#ffc107"></i> Software Licenses
    </div>
    <div class="swp-tbl-scroll">
    <table class="swp-tbl">
      <thead>
        <tr>
          <th>Software</th>
          <th>Vendor</th>
          <th>Total</th>
          <th>Used</th>
          <th>Avail.</th>
          <th>Usage</th>
          <th>Cost / Seat</th>
          <th>Expiration</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($licenses)): ?>
        <tr><td colspan="10" class="swp-empty">No licenses yet. Click "Add License" to create one.</td></tr>
      <?php else: ?>
        <?php foreach ($licenses as $l):
          $used  = (int)$l['used_seats'];
          $total = (int)$l['total_seats'];
          $avail = $total - $used;
          $pct   = $total > 0 ? min(100, round(($used / $total) * 100)) : 0;
          $bc    = $pct >= 100 ? 'swp-bar-full' : ($pct >= 70 ? 'swp-bar-warn' : 'swp-bar-ok');

          $expDays = !empty($l['expiration_date'])
                   ? (strtotime($l['expiration_date']) - time()) / 86400
                   : 9999;
          if ($expDays < 0)      { $st = 'Expired';       $sc = 'danger'; }
          elseif ($expDays < 30) { $st = 'Expiring Soon'; $sc = 'warning'; }
          else                   { $st = 'Active';         $sc = 'success'; }

          // Safe data attributes
          $da_name   = htmlspecialchars($l['name'],                  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_vendor = htmlspecialchars($l['vendor'],                ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_exp    = htmlspecialchars($l['expiration_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_notes  = htmlspecialchars($l['notes'] ?? '',           ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($l['name']) ?></strong>
            <?php if (!empty($l['notes'])): ?>
              <br><small style="color:#bbb"><?= htmlspecialchars($l['notes']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($l['vendor']) ?></td>
          <td><?= $total ?></td>
          <td><?= $used ?></td>
          <td><strong style="color:<?= $avail > 0 ? '#28a745' : '#dc3545' ?>"><?= $avail ?></strong></td>
          <td>
            <div class="swp-prog-wrap">
              <div class="swp-prog">
                <div class="swp-bar <?= $bc ?>" style="width:<?= $pct ?>%"></div>
              </div>
              <span style="font-size:12px;color:#666"><?= $pct ?>%</span>
            </div>
          </td>
          <td><?= peso($l['cost_per_seat']) ?></td>
          <td style="font-size:12px;color:#555"><?= !empty($l['expiration_date']) ? htmlspecialchars($l['expiration_date']) : '—' ?></td>
          <td><span class="swp-badge swp-badge-<?= $sc ?>"><?= $st ?></span></td>
          <td style="white-space:nowrap">

            <?php if (hasRole('Admin', 'Technician')): ?>
              <?php if ($avail > 0): ?>
              <button type="button"
                class="swp-ib swp-ib-green js-assign"
                title="Assign Seat"
                data-id="<?= (int)$l['id'] ?>"
                data-name="<?= $da_name ?>">
                <i class="fas fa-user-plus"></i>
              </button>
              <?php else: ?>
              <button type="button" class="swp-ib swp-ib-off" title="No seats available" disabled>
                <i class="fas fa-user-plus"></i>
              </button>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (hasRole('Admin')): ?>
            <button type="button"
              class="swp-ib swp-ib-blue js-editlic"
              title="Edit License"
              data-id="<?= (int)$l['id'] ?>"
              data-name="<?= $da_name ?>"
              data-vendor="<?= $da_vendor ?>"
              data-total="<?= $total ?>"
              data-cost="<?= (float)$l['cost_per_seat'] ?>"
              data-exp="<?= $da_exp ?>"
              data-notes="<?= $da_notes ?>">
              <i class="fas fa-edit"></i>
            </button>

            <?php if ($used === 0): ?>
            <button type="button"
              class="swp-ib swp-ib-red js-delic"
              title="Delete License"
              data-id="<?= (int)$l['id'] ?>"
              data-name="<?= $da_name ?>">
              <i class="fas fa-trash"></i>
            </button>
            <?php else: ?>
            <button type="button" class="swp-ib swp-ib-off" title="Cannot delete — seats in use" disabled>
              <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
            <?php endif; ?>

          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- ─── Installed Software Table ─────────────────────────────────────────── -->
  <div class="swp-card">
    <div class="swp-card-title">
      <i class="fas fa-desktop" style="color:#28a745"></i> Installed Software
    </div>
    <div class="swp-tbl-scroll">
    <table class="swp-tbl">
      <thead>
        <tr>
          <th>Asset Tag</th>
          <th>Employee</th>
          <th>Software</th>
          <th>Version</th>
          <th>License Key</th>
          <th>Install Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($installed)): ?>
        <tr><td colspan="7" class="swp-empty">No installed software records yet.</td></tr>
      <?php else: ?>
        <?php foreach ($installed as $s):
          $da_asset = htmlspecialchars($s['asset_tag']    ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_ver   = htmlspecialchars($s['version']      ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_key   = htmlspecialchars($s['license_key']  ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_date  = htmlspecialchars($s['install_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $da_sname = htmlspecialchars($s['name'],               ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
        <tr>
          <td>
            <?php if (!empty($s['asset_id'])): ?>
              <a href="asset_view.php?id=<?= (int)$s['asset_id'] ?>" style="color:#007bff;text-decoration:none;font-weight:600">
                <?= htmlspecialchars($s['asset_tag'] ?? '—') ?>
              </a>
            <?php else: ?>
              <span style="color:#999"><?= htmlspecialchars($s['asset_tag'] ?? '—') ?></span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($s['employee_name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($s['name']) ?></td>
          <td><?= htmlspecialchars($s['version'] ?? '—') ?></td>
          <td><code><?= htmlspecialchars($s['license_key'] ?? '—') ?></code></td>
          <td style="font-size:12px;color:#555"><?= htmlspecialchars($s['install_date'] ?? '—') ?></td>
          <td style="white-space:nowrap">

            <?php if (hasRole('Admin', 'Technician')): ?>
            <button type="button"
              class="swp-ib swp-ib-blue js-editinst"
              title="Edit"
              data-id="<?= (int)$s['id'] ?>"
              data-asset="<?= $da_asset ?>"
              data-version="<?= $da_ver ?>"
              data-key="<?= $da_key ?>"
              data-date="<?= $da_date ?>">
              <i class="fas fa-edit"></i>
            </button>
            <?php endif; ?>

            <?php if (hasRole('Admin')): ?>
            <button type="button"
              class="swp-ib swp-ib-red js-delinst"
              title="Remove / Unassign"
              data-id="<?= (int)$s['id'] ?>"
              data-name="<?= $da_sname ?>"
              data-asset="<?= $da_asset ?>">
              <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>

          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

</div><!-- /.swp -->


<!-- ════════════ MODAL: Add License ════════════ -->
<div class="swp-overlay" id="sw_mAdd" style="display:none">
  <div class="swp-modal">
    <button type="button" class="swp-mclose js-close" data-target="sw_mAdd">&times;</button>
    <h3><i class="fas fa-plus-circle" style="color:#28a745"></i> Add License</h3>
    <form method="POST" action="software.php" autocomplete="off">
      <input type="hidden" name="action" value="add_license">
      <div class="swp-fg">
        <label>Software Name <span style="color:red">*</span></label>
        <input type="text" name="name" required placeholder="e.g. Microsoft Office 365">
      </div>
      <div class="swp-fg">
        <label>Vendor <span style="color:red">*</span></label>
        <input type="text" name="vendor" required placeholder="e.g. Microsoft">
      </div>
      <div class="swp-frow">
        <div class="swp-fg">
          <label>Total Seats <span style="color:red">*</span></label>
          <input type="number" name="total_seats" min="1" required placeholder="25">
        </div>
        <div class="swp-fg">
          <label>Cost per Seat (&#8369;)</label>
          <input type="number" name="cost_per_seat" step="0.01" min="0" value="0">
        </div>
      </div>
      <div class="swp-fg">
        <label>Expiration Date</label>
        <input type="date" name="expiration_date">
      </div>
      <div class="swp-fg">
        <label>Notes</label>
        <textarea name="notes" rows="2" placeholder="Optional notes..."></textarea>
      </div>
      <div class="swp-mfoot">
        <button type="button" class="swp-btn swp-btn-gray js-close" data-target="sw_mAdd">Cancel</button>
        <button type="submit" class="swp-btn swp-btn-green"><i class="fas fa-save"></i> Save License</button>
      </div>
    </form>
  </div>
</div>


<!-- ════════════ MODAL: Edit License ════════════ -->
<div class="swp-overlay" id="sw_mEditLic" style="display:none">
  <div class="swp-modal">
    <button type="button" class="swp-mclose js-close" data-target="sw_mEditLic">&times;</button>
    <h3><i class="fas fa-edit" style="color:#007bff"></i> Edit License</h3>
    <form method="POST" action="software.php" autocomplete="off">
      <input type="hidden" name="action" value="edit_license">
      <input type="hidden" name="license_id" id="el_id">
      <div class="swp-fg">
        <label>Software Name <span style="color:red">*</span></label>
        <input type="text" name="name" id="el_name" required>
      </div>
      <div class="swp-fg">
        <label>Vendor <span style="color:red">*</span></label>
        <input type="text" name="vendor" id="el_vendor" required>
      </div>
      <div class="swp-frow">
        <div class="swp-fg">
          <label>Total Seats <span style="color:red">*</span></label>
          <input type="number" name="total_seats" id="el_total" min="1" required>
        </div>
        <div class="swp-fg">
          <label>Cost per Seat (&#8369;)</label>
          <input type="number" name="cost_per_seat" id="el_cost" step="0.01" min="0">
        </div>
      </div>
      <div class="swp-fg">
        <label>Expiration Date</label>
        <input type="date" name="expiration_date" id="el_exp">
      </div>
      <div class="swp-fg">
        <label>Notes</label>
        <textarea name="notes" id="el_notes" rows="2"></textarea>
      </div>
      <div class="swp-mfoot">
        <button type="button" class="swp-btn swp-btn-gray js-close" data-target="sw_mEditLic">Cancel</button>
        <button type="submit" class="swp-btn swp-btn-blue"><i class="fas fa-save"></i> Update License</button>
      </div>
    </form>
  </div>
</div>


<!-- ════════════ MODAL: Assign Seat ════════════ -->
<div class="swp-overlay" id="sw_mAssign" style="display:none">
  <div class="swp-modal swp-modal-sm">
    <button type="button" class="swp-mclose js-close" data-target="sw_mAssign">&times;</button>
    <h3><i class="fas fa-user-plus" style="color:#28a745"></i> Assign Seat</h3>
    <div style="background:#f0f7ff;border-radius:7px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#004085">
      <i class="fas fa-info-circle"></i>
      Assigning seat for: <strong id="sw_assignName" style="color:#007bff"></strong>
    </div>
    <form method="POST" action="software.php" autocomplete="off">
      <input type="hidden" name="action" value="assign_seat">
      <input type="hidden" name="license_id" id="sw_assignLid">
      <div class="swp-fg">
        <label>Asset Tag</label>
        <input type="text" name="asset_tag" placeholder="e.g. IT-001">
      </div>
      <div class="swp-fg">
        <label>Version</label>
        <input type="text" name="version" placeholder="e.g. 16.0">
      </div>
      <div class="swp-fg">
        <label>License Key</label>
        <input type="text" name="license_key" placeholder="XXXXX-XXXXX-XXXXX">
      </div>
      <div class="swp-fg">
        <label>Install Date</label>
        <input type="date" name="install_date" id="sw_assignDate">
      </div>
      <div class="swp-mfoot">
        <button type="button" class="swp-btn swp-btn-gray js-close" data-target="sw_mAssign">Cancel</button>
        <button type="submit" class="swp-btn swp-btn-green"><i class="fas fa-check"></i> Assign Seat</button>
      </div>
    </form>
  </div>
</div>


<!-- ════════════ MODAL: Edit Installed ════════════ -->
<div class="swp-overlay" id="sw_mEditInst" style="display:none">
  <div class="swp-modal swp-modal-sm">
    <button type="button" class="swp-mclose js-close" data-target="sw_mEditInst">&times;</button>
    <h3><i class="fas fa-edit" style="color:#007bff"></i> Edit Installed Software</h3>
    <form method="POST" action="software.php" autocomplete="off">
      <input type="hidden" name="action" value="edit_installed">
      <input type="hidden" name="installed_id" id="ei_id">
      <div class="swp-fg">
        <label>Asset Tag</label>
        <input type="text" name="asset_tag" id="ei_asset" placeholder="e.g. IT-001">
      </div>
      <div class="swp-fg">
        <label>Version</label>
        <input type="text" name="version" id="ei_version">
      </div>
      <div class="swp-fg">
        <label>License Key</label>
        <input type="text" name="license_key" id="ei_key">
      </div>
      <div class="swp-fg">
        <label>Install Date</label>
        <input type="date" name="install_date" id="ei_date">
      </div>
      <div class="swp-mfoot">
        <button type="button" class="swp-btn swp-btn-gray js-close" data-target="sw_mEditInst">Cancel</button>
        <button type="submit" class="swp-btn swp-btn-blue"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>


<!-- ════════════ MODAL: Confirm Delete ════════════ -->
<div class="swp-overlay" id="sw_mDelete" style="display:none">
  <div class="swp-modal swp-modal-sm">
    <button type="button" class="swp-mclose js-close" data-target="sw_mDelete">&times;</button>
    <h3><i class="fas fa-exclamation-triangle" style="color:#dc3545"></i> Confirm Delete</h3>
    <p class="swp-confirm-msg" id="sw_delMsg"></p>
    <form method="POST" action="software.php" id="sw_delForm">
      <input type="hidden" name="action"       id="sw_delAction">
      <input type="hidden" name="license_id"   id="sw_delLicId">
      <input type="hidden" name="installed_id" id="sw_delInstId">
      <div class="swp-mfoot">
        <button type="button" class="swp-btn swp-btn-gray js-close" data-target="sw_mDelete">Cancel</button>
        <button type="submit" class="swp-btn swp-btn-red"><i class="fas fa-trash"></i> Yes, Delete</button>
      </div>
    </form>
  </div>
</div>


<script>
/* ── Runs after DOM is fully loaded ── */
document.addEventListener('DOMContentLoaded', function () {

  /* helper: get element by id */
  function g(id) { return document.getElementById(id); }

  /* open/close using style.display directly — immune to CSS class conflicts */
  function openModal(id)  { var m = g(id); if (m) m.style.display = 'flex'; }
  function closeModal(id) { var m = g(id); if (m) m.style.display = 'none'; }

  /* ── Close on backdrop click ── */
  document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('swp-overlay')) {
      e.target.style.display = 'none';
    }
  });

  /* ── Close buttons via data-target ── */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-close');
    if (btn) {
      var target = btn.getAttribute('data-target');
      if (target) closeModal(target);
    }
  });

  /* ── ESC key closes any open modal ── */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var overlays = document.querySelectorAll('.swp-overlay');
      overlays.forEach(function (o) { o.style.display = 'none'; });
    }
  });

  /* ════════ ADD LICENSE ════════ */
  var btnOpenAdd = g('sw_openAdd');
  if (btnOpenAdd) {
    btnOpenAdd.addEventListener('click', function () {
      openModal('sw_mAdd');
    });
  }

  /* ════════ EDIT LICENSE ════════ */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-editlic');
    if (!btn) return;
    g('el_id').value     = btn.getAttribute('data-id')     || '';
    g('el_name').value   = btn.getAttribute('data-name')   || '';
    g('el_vendor').value = btn.getAttribute('data-vendor') || '';
    g('el_total').value  = btn.getAttribute('data-total')  || '';
    g('el_cost').value   = btn.getAttribute('data-cost')   || '0';
    g('el_exp').value    = btn.getAttribute('data-exp')    || '';
    g('el_notes').value  = btn.getAttribute('data-notes')  || '';
    openModal('sw_mEditLic');
  });

  /* ════════ ASSIGN SEAT ════════ */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-assign');
    if (!btn) return;
    g('sw_assignLid').value        = btn.getAttribute('data-id')   || '';
    g('sw_assignName').textContent = btn.getAttribute('data-name') || '';
    g('sw_assignDate').value       = new Date().toISOString().slice(0, 10);
    openModal('sw_mAssign');
  });

  /* ════════ EDIT INSTALLED ════════ */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-editinst');
    if (!btn) return;
    g('ei_id').value      = btn.getAttribute('data-id')      || '';
    g('ei_asset').value   = btn.getAttribute('data-asset')   || '';
    g('ei_version').value = btn.getAttribute('data-version') || '';
    g('ei_key').value     = btn.getAttribute('data-key')     || '';
    g('ei_date').value    = btn.getAttribute('data-date')    || '';
    openModal('sw_mEditInst');
  });

  /* ════════ DELETE LICENSE ════════ */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-delic');
    if (!btn) return;
    var name = btn.getAttribute('data-name') || 'this license';
    g('sw_delMsg').textContent  = 'Are you sure you want to permanently delete "' + name + '"? This cannot be undone.';
    g('sw_delAction').value     = 'delete_license';
    g('sw_delLicId').value      = btn.getAttribute('data-id') || '';
    g('sw_delInstId').value     = '';
    openModal('sw_mDelete');
  });

  /* ════════ DELETE INSTALLED ════════ */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-delinst');
    if (!btn) return;
    var name  = btn.getAttribute('data-name')  || 'this software';
    var asset = btn.getAttribute('data-asset') || '—';
    g('sw_delMsg').textContent  = 'Remove "' + name + '" from asset ' + asset + '? One license seat will be freed.';
    g('sw_delAction').value     = 'delete_installed';
    g('sw_delInstId').value     = btn.getAttribute('data-id') || '';
    g('sw_delLicId').value      = '';
    openModal('sw_mDelete');
  });

}); /* end DOMContentLoaded */
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>