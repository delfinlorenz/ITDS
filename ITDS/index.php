<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl() . '/pages/dashboard.php');
    exit;
}

$error      = '';
$errorField = '';

if (isPost()) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error      = 'Please enter your username and password.';
        $errorField = 'both';
    } else {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            $error      = 'Username does not exist.';
            $errorField = 'username';
        } elseif (!password_verify($password, $user['password'])) {
            $error      = 'Incorrect password.';
            $errorField = 'password';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user']    = [
                'id'       => $user['id'],
                'name'     => $user['name'],
                'username' => $user['username'],
                'role'     => $user['role'],
                'company'  => $user['company'],
            ];
            db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            addAuditLog('Logged In', "Role: {$user['role']}, Company: {$user['company']}");
            addNotification($user['id'], 'Welcome back', "Logged in as {$user['name']} ({$user['role']})", 'success');
            header('Location: ' . baseUrl() . '/pages/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITDS Inventory Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%;
            overflow: hidden; /* prevent page scroll — everything fits viewport */
        }

        body {
            font-family: 'Inter', sans-serif;
            display: flex;
            height: 100vh;
        }

        /* Preloader Animation */
        .preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(160deg, #1e40af 0%, #1e3a5f 55%, #0f172a 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.6s ease-out, visibility 0.6s ease-out;
        }
        .preloader.fade-out {
            opacity: 0;
            visibility: hidden;
        }
        .loader-container {
            text-align: center;
            animation: scaleIn 0.5s ease-out;
        }
        .loader-icon {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,.12);
            border: 2px solid rgba(255,255,255,.2);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            animation: pulseGlow 2s infinite;
            position: relative;
        }
        .loader-icon i {
            font-size: 3.2rem;
            color: #fff;
            animation: rotate 3s infinite linear;
        }
        .loader-text {
            color: #fff;
            font-size: 1.8rem;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 10px;
            opacity: 0;
            animation: slideUp 0.5s ease-out 0.2s forwards;
        }
        .loader-subtext {
            color: rgba(255,255,255,.6);
            font-size: 0.95rem;
            font-weight: 400;
            opacity: 0;
            animation: slideUp 0.5s ease-out 0.3s forwards;
        }
        .loader-dots {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 25px;
        }
        .dot {
            width: 10px;
            height: 10px;
            background: rgba(255,255,255,.5);
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out;
        }
        .dot:nth-child(1) { animation-delay: -0.32s; }
        .dot:nth-child(2) { animation-delay: -0.16s; }
        
        /* Loading progress bar */
        .progress-bar {
            width: 200px;
            height: 3px;
            background: rgba(255,255,255,.1);
            border-radius: 10px;
            margin: 20px auto 0;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #60a5fa, #3b82f6);
            border-radius: 10px;
            animation: progress 2s ease-in-out forwards;
        }

        @keyframes scaleIn {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        @keyframes pulseGlow {
            0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); }
            70% { box-shadow: 0 0 0 20px rgba(59, 130, 246, 0); }
            100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        
        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes slideUp {
            0% { transform: translateY(20px); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1); }
        }
        
        @keyframes progress {
            0% { width: 0%; }
            20% { width: 30%; }
            50% { width: 60%; }
            80% { width: 85%; }
            100% { width: 100%; }
        }

        /* ── Left panel ── */
        .left-panel {
            flex: 1;
            background: linear-gradient(160deg, #1e40af 0%, #1e3a5f 55%, #0f172a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 48px;
            position: relative;
            overflow: hidden;
        }
        .left-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .left-inner {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 380px;
            width: 100%;
        }
        .brand-icon {
            width: 72px; height: 72px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.2);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; color: #fff;
            margin: 0 auto 22px;
        }
        .brand-name {
            font-size: 1.6rem; font-weight: 700;
            color: #fff; line-height: 1.25;
            margin-bottom: 10px; letter-spacing: -.2px;
        }
        .brand-desc {
            font-size: .88rem;
            color: rgba(255,255,255,.55);
            line-height: 1.6;
            margin-bottom: 36px;
        }
        .features { display: flex; flex-direction: column; gap: 10px; }
        .feature-item {
            display: flex; align-items: center; gap: 13px;
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 11px;
            padding: 12px 16px;
        }
        .feature-icon {
            width: 34px; height: 34px;
            background: rgba(59,130,246,.35);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: #93c5fd; font-size: .88rem; flex-shrink: 0;
        }
        .feature-text { font-size: .83rem; color: rgba(255,255,255,.72); font-weight: 500; }

        /* ── Right panel ── */
        .right-panel {
            width: 440px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            padding: 40px 44px;
            opacity: 0;
            animation: fadeInContent 0.8s ease-out 2.2s forwards;
        }

        @keyframes fadeInContent {
            0% { opacity: 0; transform: translateY(10px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .form-wrap { width: 100%; }

        .form-header { margin-bottom: 28px; }
        .form-header h2 { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: 5px; }
        .form-header p  { font-size: .875rem; color: #64748b; }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: .75rem; font-weight: 600;
            color: #475569; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: .5px;
        }
        .input-wrap { position: relative; }
        .input-wrap .fi {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8; font-size: .85rem; pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 11px 12px 11px 36px;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            font-size: .93rem;
            font-family: 'Inter', sans-serif;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
            /* prevent browser autofill from pre-populating visually */
        }
        .input-wrap input:focus {
            border-color: #3b82f6;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(59,130,246,.11);
        }
        .input-wrap input.err {
            border-color: #f87171;
            background: #fff8f8;
        }
        .input-wrap input.err:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239,68,68,.1);
        }
        .toggle-pw {
            position: absolute; right: 11px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            cursor: pointer; color: #94a3b8;
            font-size: .85rem; padding: 4px; line-height: 1;
        }
        .toggle-pw:hover { color: #475569; }

        .field-err {
            display: flex; align-items: center; gap: 5px;
            margin-top: 5px; font-size: .8rem;
            color: #dc2626; font-weight: 500;
        }
        .field-err i { font-size: .76rem; }

        .submit-btn {
            width: 100%; background: #2563eb; color: #fff;
            border: none; padding: 12px;
            border-radius: 9px; font-size: .96rem;
            font-weight: 600; font-family: 'Inter', sans-serif;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background .15s, transform .1s, box-shadow .15s;
            margin-top: 6px;
        }
        .submit-btn:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 5px 18px rgba(37,99,235,.28);
        }
        .submit-btn:active { transform: scale(.98); }

        .form-footer {
            margin-top: 28px; font-size: .77rem;
            color: #94a3b8; text-align: center; line-height: 1.5;
        }

        @media (max-width: 860px) {
            html, body { overflow: auto; }
            .left-panel { display: none; }
            .right-panel { width: 100%; padding: 40px 24px; }
        }
    </style>
</head>
<body>

<!-- Preloader Animation -->
<div class="preloader" id="preloader">
    <div class="loader-container">
        <div class="loader-icon">
            <i class="fas fa-microchip"></i>
        </div>
        <div class="loader-text">ITDS Inventory</div>
        <div class="loader-subtext">Loading secure system...</div>
        <div class="loader-dots">
            <div class="dot"></div>
            <div class="dot"></div>
            <div class="dot"></div>
        </div>
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
    </div>
</div>

<div class="left-panel">
    <div class="left-inner">
        <div class="brand-icon"><i class="fas fa-microchip"></i></div>
        <div class="brand-name">ITDS Inventory<br>Management System</div>
        <p class="brand-desc">
            A centralized platform for tracking IT assets,<br>
            managing maintenance, and optimizing resources.
        </p>
        <div class="features">
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-laptop"></i></div>
                <div class="feature-text">Computer &amp; Asset Inventory Tracking</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-tools"></i></div>
                <div class="feature-text">Maintenance Scheduling &amp; History</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <div class="feature-text">Budget Forecasting &amp; Reports</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-key"></i></div>
                <div class="feature-text">Software License Management</div>
            </div>
        </div>
    </div>
</div>

<div class="right-panel">
    <div class="form-wrap">

        <div class="form-header">
            <h2>Welcome back</h2>
            <p>Sign in to your account to continue</p>
        </div>

        <form method="POST" autocomplete="off">

            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrap">
                    <i class="fas fa-user fi"></i>
                    <input type="text"
                           id="username"
                           name="username"
                           placeholder="Enter your username"
                           required
                           autofocus
                           autocomplete="off"
                           value=""
                           class="<?= $errorField === 'username' || $errorField === 'both' ? 'err' : '' ?>">
                </div>
                <?php if ($errorField === 'username'): ?>
                <div class="field-err"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock fi"></i>
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="Enter your password"
                           required
                           autocomplete="new-password"
                           class="<?= $errorField === 'password' || $errorField === 'both' ? 'err' : '' ?>">
                    <button type="button" class="toggle-pw" onclick="togglePw()">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
                <?php if ($errorField === 'password'): ?>
                <div class="field-err"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($errorField === 'both'): ?>
            <div class="field-err" style="margin-bottom:12px;font-size:.85rem">
                <i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?>
            </div>
            <?php endif; ?>

            <button type="submit" class="submit-btn">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>

        </form>

        <div class="form-footer">
            &copy; <?= date('Y') ?> ITDS &mdash; IT Department Management System &mdash; v1.0.0
        </div>

    </div>
</div>

<script>
function togglePw() {
    var inp  = document.getElementById('password');
    var icon = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

// Hide preloader after animation completes
window.addEventListener('load', function() {
    setTimeout(function() {
        document.getElementById('preloader').classList.add('fade-out');
    }, 2200); // 2.2 seconds to match animation timing
});
</script>

</body>
</html>