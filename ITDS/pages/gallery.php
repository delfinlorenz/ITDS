<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$pageTitle   = 'Asset Gallery';
$currentPage = 'gallery';
$db = db();

// Filter
$search = $_GET['search'] ?? '';
$type   = $_GET['type']   ?? '';

$where = []; $params = [];
if ($search) { $where[] = "(a.asset_tag LIKE ? OR a.brand LIKE ? OR a.model LIKE ? OR e.name LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like,$like,$like]); }
if ($type)   { $where[] = "a.device_type=?"; $params[] = $type; }
$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

$assets = $db->prepare("SELECT a.id, a.asset_tag, a.brand, a.model, a.device_type, a.status, a.lifecycle_state,
    a.purchase_cost, a.processor, a.ram, a.storage, a.operating_system, a.photo_url,
    e.name AS emp_name, d.name AS dept_name
    FROM assets a
    LEFT JOIN employees e ON a.employee_id=e.id
    LEFT JOIN departments d ON e.department_id=d.id
    $whereSQL
    ORDER BY a.asset_tag ASC LIMIT 200");
$assets->execute($params);
$assets = $assets->fetchAll();

$types = $db->query("SELECT DISTINCT device_type FROM assets ORDER BY device_type")->fetchAll(PDO::FETCH_COLUMN);

// Icon mapping
function deviceIcon($type) {
    return match(strtolower($type ?? '')) {
        'laptop', 'notebook' => 'fa-laptop',
        'desktop', 'tower'   => 'fa-desktop',
        'server'             => 'fa-server',
        'tablet'             => 'fa-tablet-alt',
        'printer'            => 'fa-print',
        'monitor'            => 'fa-tv',
        default              => 'fa-desktop',
    };
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
    <h2><i class="fas fa-images"></i> Asset Gallery</h2>
    <span style="color:var(--text-muted)"><?= count($assets) ?> assets</span>
</div>

<form method="GET">
<div class="filter-bar">
    <input class="filter-select" name="search" placeholder="Search asset tag, brand, model, employee..." value="<?= sanitize($search) ?>" style="flex:1">
    <select class="filter-select" name="type">
        <option value="">All Types</option>
        <?php foreach ($types as $t): ?>
        <option value="<?= sanitize($t) ?>" <?= $type===$t?'selected':'' ?>><?= sanitize($t) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-search"></i> Search</button>
    <a href="gallery.php" class="btn btn-secondary">Clear</a>
</div>
</form>

<div class="gallery-grid">
<?php foreach ($assets as $a): ?>
<div class="gallery-card" onclick="window.location='asset_view.php?id=<?= urlencode($a['id']) ?>'">
    <!-- Device Image / Icon -->
    <div class="gallery-img">
        <?php if (!empty($a['photo_url'])): ?>
            <img src="<?= sanitize($a['photo_url']) ?>" alt="<?= sanitize($a['asset_tag']) ?>" loading="lazy">
        <?php else: ?>
            <i class="fas <?= deviceIcon($a['device_type']) ?>"></i>
        <?php endif; ?>
        <div class="gallery-status-badge status-<?= $a['status']==='Active'?'active':($a['status']==='Maintenance'?'maintenance':($a['status']==='Lost'||$a['status']==='Stolen'?'lost':'retired')) ?>">
            <?= sanitize($a['status']) ?>
        </div>
    </div>
    <div class="gallery-body">
        <div class="gallery-tag"><?= sanitize($a['asset_tag']) ?></div>
        <div class="gallery-device"><?= sanitize($a['brand'].' '.$a['model']) ?></div>
        <div class="gallery-employee"><i class="fas fa-user"></i> <?= sanitize($a['emp_name'] ?? 'Unassigned') ?></div>
        <div class="gallery-dept"><i class="fas fa-building"></i> <?= sanitize($a['dept_name'] ?? '—') ?></div>
        <div class="gallery-specs">
            <?php if ($a['processor']): ?><span><?= sanitize(substr($a['processor'],0,20)) ?></span><?php endif; ?>
            <?php if ($a['ram']): ?><span><?= sanitize($a['ram']) ?></span><?php endif; ?>
            <?php if ($a['storage']): ?><span><?= sanitize($a['storage']) ?></span><?php endif; ?>
        </div>
        <div class="gallery-value"><?= peso($a['purchase_cost'] ?? 0) ?></div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($assets)): ?>
<div style="grid-column:1/-1">
    <div class="empty-state"><i class="fas fa-images"></i><p>No assets found.</p></div>
</div>
<?php endif; ?>
</div>

<style>
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 18px;
    margin-top: 4px;
}
.gallery-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
}
.gallery-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
}
.gallery-img {
    height: 140px;
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    color: var(--text-muted);
    position: relative;
    overflow: hidden;
}
.gallery-img img { width:100%; height:100%; object-fit:cover; }
.gallery-status-badge {
    position: absolute; top: 8px; right: 8px;
    font-size: .7rem; padding: 3px 8px; border-radius: 99px;
    font-weight: 600; color: #fff;
}
.status-active   { background: var(--success); }
.status-maintenance { background: var(--warning); }
.status-lost     { background: var(--danger); }
.status-retired  { background: var(--text-muted); }
.gallery-body { padding: 12px 14px; }
.gallery-tag { font-size: .75rem; color: var(--text-muted); }
.gallery-device { font-weight: 700; font-size: .95rem; margin: 2px 0 6px; color: var(--text-primary); }
.gallery-employee, .gallery-dept { font-size: .82rem; color: var(--text-secondary); margin-bottom: 2px; }
.gallery-employee i, .gallery-dept i { width: 14px; }
.gallery-specs { display: flex; flex-wrap: wrap; gap: 4px; margin: 8px 0; }
.gallery-specs span { font-size: .72rem; background: var(--bg-tertiary); padding: 2px 7px; border-radius: 4px; color: var(--text-muted); }
.gallery-value { font-weight: 600; color: var(--primary); font-size: .9rem; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
