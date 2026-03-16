<?php
// ============================================================
// config.php — Database & App Configuration
// ============================================================

define('DB_HOST',    '127.0.0.1');
define('DB_NAME',    'itam_db');
define('DB_USER',    'root');
define('DB_PASS',    'root');           // Set your MySQL password here
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'ITDS Inventory Management System');
define('APP_VERSION', '2.0');
define('CURRENCY',    '₱');
define('TIMEZONE',    'Asia/Manila');

date_default_timezone_set(TIMEZONE);

// ============================================================
// BASE URL — auto-detected from server environment
// ============================================================
function baseUrl(): string {
    static $base = null;
    if ($base === null) {
        $appRoot  = str_replace('\\', '/', dirname(dirname(__FILE__)));
        $docRoot  = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
        $base     = str_replace($docRoot, '', $appRoot);
        $base     = '/' . trim($base, '/');
    }
    return $base;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// PDO Database Connection (singleton)
// ============================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;background:#fef2f2;color:#dc2626;border-radius:12px;max-width:600px;margin:40px auto">
                <h2>&#9888; Database Connection Failed</h2>
                <p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
                <p>Open <strong>includes/config.php</strong> and set <code>DB_PASS</code> to your MySQL root password.</p>
            </div>');
        }
    }
    return $pdo;
}

// ============================================================
// Auth Helpers
// ============================================================
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . baseUrl() . '/index.php');
        exit;
    }
}

function currentUser(): array {
    return $_SESSION['user'] ?? [];
}

function hasRole(string ...$roles): bool {
    return in_array(currentUser()['role'] ?? '', $roles);
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center">
            <h2>Access Denied</h2><p>You do not have permission to view this page.</p>
            <a href="' . baseUrl() . '/pages/dashboard.php" style="color:#2563eb">← Back to Dashboard</a>
        </div>');
    }
}

// ============================================================
// Response Helpers
// ============================================================
function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function jsonError(string $msg, int $code = 400): void { jsonResponse(['error' => $msg], $code); }
function jsonSuccess(string $msg, array $data = []): void { jsonResponse(array_merge(['success' => true, 'message' => $msg], $data)); }

// ============================================================
// Utility Helpers
// ============================================================
function peso(float $amount): string {
    return CURRENCY . number_format($amount, 2);
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateId(string $prefix, int $num): string {
    return $prefix . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function addAuditLog(string $action, string $details = ''): void {
    $user = currentUser();
    if (empty($user)) return;
    try {
        db()->prepare("INSERT INTO audit_log (user_id, user_name, user_role, company, action, details, ip_address) VALUES (?,?,?,?,?,?,?)")
           ->execute([$user['id']??0, $user['name']??'System', $user['role']??'', $user['company']??'', $action, $details, $_SERVER['REMOTE_ADDR']??'']);
    } catch (Exception $e) {}
}

function addNotification(int $userId, string $title, string $message, string $type = 'info'): void {
    try {
        db()->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,?)")
           ->execute([$userId, $title, $message, $type]);
    } catch (Exception $e) {}
}

function getUnreadNotificationCount(): int {
    if (!isLoggedIn()) return 0;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$_SESSION['user_id']]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function calculateDepreciation(float $cost, ?string $purchaseDate, float $yearsLife = 5): float {
    if (!$cost || !$purchaseDate) return $cost;
    $age  = (time() - strtotime($purchaseDate)) / (365.25 * 24 * 3600);
    $book = $cost - ($cost / $yearsLife) * $age;
    return max(0, round($book, 2));
}

function isPost(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function isGet():  bool { return $_SERVER['REQUEST_METHOD'] === 'GET'; }
