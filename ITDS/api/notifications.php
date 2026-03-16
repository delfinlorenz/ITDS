<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();

$db = db();
$count = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$count->execute([$_SESSION['user_id']]);
$count = (int)$count->fetchColumn();

$recent = $db->prepare("SELECT * FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 5");
$recent->execute([$_SESSION['user_id']]);
$recent = $recent->fetchAll();

jsonResponse(['count' => $count, 'notifications' => $recent]);
