<?php
// api/search.php — Global smart search endpoint
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { jsonResponse(['results' => []]); }

$like    = "%$q%";
$results = [];
$db      = db();

// Assets
$stmt = $db->prepare("SELECT a.id, a.asset_tag, a.serial_number, e.name AS emp_name, d.name AS dept
    FROM assets a
    LEFT JOIN employees e ON a.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE a.asset_tag LIKE ? OR a.serial_number LIKE ? OR e.name LIKE ? OR e.emp_id LIKE ? OR a.processor LIKE ? OR a.hostname LIKE ?
    LIMIT 5");
$stmt->execute([$like, $like, $like, $like, $like, $like]);
foreach ($stmt->fetchAll() as $r) {
    $results[] = [
        'icon'     => 'desktop',
        'title'    => $r['asset_tag'],
        'subtitle' => ($r['emp_name'] ?? '—') . ' · ' . ($r['dept'] ?? '—'),
        'url'      => baseUrl() . '/pages/asset_view.php?id=' . $r['id'],
    ];
}

// Employees
$stmt = $db->prepare("SELECT id, emp_id, name, position FROM employees WHERE name LIKE ? OR emp_id LIKE ? LIMIT 4");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $r) {
    $results[] = [
        'icon'     => 'user',
        'title'    => $r['name'],
        'subtitle' => $r['emp_id'] . ' · ' . ($r['position'] ?? ''),
        'url'      => baseUrl() . '/pages/employees.php?search=' . urlencode($r['name']),
    ];
}

// Supplies
$stmt = $db->prepare("SELECT id, type, brand, model, status FROM supplies WHERE id LIKE ? OR type LIKE ? OR brand LIKE ? OR serial_number LIKE ? LIMIT 3");
$stmt->execute([$like, $like, $like, $like]);
foreach ($stmt->fetchAll() as $r) {
    $results[] = [
        'icon'     => 'box',
        'title'    => $r['id'] . ' — ' . $r['type'],
        'subtitle' => $r['brand'] . ' ' . $r['model'] . ' (' . $r['status'] . ')',
        'url'      => baseUrl() . '/pages/supplies.php',
    ];
}

// Maintenance tasks
$stmt = $db->prepare("SELECT mt.id, mt.task_name, mt.status, a.asset_tag FROM maintenance_tasks mt
    LEFT JOIN assets a ON mt.asset_id = a.id
    WHERE mt.task_name LIKE ? OR a.asset_tag LIKE ? LIMIT 3");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $r) {
    $results[] = [
        'icon'     => 'tools',
        'title'    => 'Task: ' . $r['task_name'],
        'subtitle' => ($r['asset_tag'] ?? '—') . ' · ' . $r['status'],
        'url'      => baseUrl() . '/pages/maintenance.php',
    ];
}

// Tickets
$stmt = $db->prepare("SELECT id, issue, employee_name, status FROM tickets WHERE issue LIKE ? OR employee_name LIKE ? LIMIT 3");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $r) {
    $results[] = [
        'icon'     => 'headset',
        'title'    => '#' . $r['id'] . ' ' . $r['issue'],
        'subtitle' => $r['employee_name'] . ' · ' . $r['status'],
        'url'      => baseUrl() . '/pages/helpdesk.php',
    ];
}

jsonResponse(['results' => $results]);
