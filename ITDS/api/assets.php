<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
requireRole('Admin','Technician');

$db     = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'delete') {
    $id = trim($_POST['id'] ?? $_GET['id'] ?? '');
    if (!$id) {
        $_SESSION['flash_msg']  = 'Asset ID required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . baseUrl() . '/pages/inventory.php');
        exit;
    }
    // Get asset tag for audit log
    $tag = $db->prepare("SELECT asset_tag FROM assets WHERE id=?");
    $tag->execute([$id]);
    $tag = $tag->fetchColumn();

    $db->prepare("DELETE FROM assets WHERE id=?")->execute([$id]);
    addAuditLog('Deleted Asset', $tag ?: $id);

    $_SESSION['flash_msg']  = "Asset $tag deleted.";
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . baseUrl() . '/pages/inventory.php');
    exit;
}

if ($action === 'get') {
    $id   = trim($_GET['id'] ?? '');
    $stmt = $db->prepare("SELECT a.*, e.name AS emp_name FROM assets a LEFT JOIN employees e ON a.employee_id=e.id WHERE a.id=?");
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset) jsonError('Not found', 404);
    jsonResponse($asset);
}

// Default: redirect back
header('Location: ' . baseUrl() . '/pages/inventory.php');
exit;
