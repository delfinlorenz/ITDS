<?php
require_once __DIR__ . '/includes/config.php';
if (isLoggedIn()) {
    addAuditLog('Logged Out', 'Session ended');
}
session_destroy();
header('Location: ' . baseUrl() . '/index.php');
exit;
