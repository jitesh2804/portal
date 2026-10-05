<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (isLoggedIn()) {
    redirect(roleHome($_SESSION['role'] ?? ''));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $agentId = trim($_POST['agent_id'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare("
            SELECT id, agent_id, full_name, password_hash, role, is_active, lob
              FROM users
             WHERE agent_id = :agent_id
             LIMIT 1
        ");
        $stmt->execute([':agent_id' => $agentId]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid Agent ID or password.');
        }

        $assignedLobs = parseAssignedLobs($user['lob']);
        if ($user['role'] !== 'admin' && $assignedLobs === []) {
            throw new RuntimeException('Your LOB is not assigned. Please contact your administrator or supervisor.');
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['agent_id'] = $user['agent_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['lob'] = $user['lob'];

        if ($user['role'] === 'agent') {
            $pdo = db();
            $pdo->beginTransaction();

            // Close accidental old open sessions for this user at last_seen.
            $closeOld = $pdo->prepare("
                UPDATE agent_sessions
                   SET logout_at = COALESCE(logout_at, last_seen)
                 WHERE user_id = :user_id
                   AND logout_at IS NULL
            ");
            $closeOld->execute([':user_id' => (int)$user['id']]);

            $insertSession = $pdo->prepare("
                INSERT INTO agent_sessions (user_id, login_ip, user_agent, lob)
                VALUES (:user_id, :login_ip, :user_agent, :lob)
                RETURNING id
            ");
            $insertSession->execute([
                ':user_id' => (int)$user['id'],
                ':login_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':lob' => $user['lob'],
            ]);
            $sessionId = (int)$insertSession->fetchColumn();

            $_SESSION['activity_session_id'] = $sessionId;
            startActivity($pdo, (int)$user['id'], $sessionId, 'IDLE');

            $pdo->commit();
            redirect('/agent/dashboard.php');
        }

        redirect(roleHome($user['role']));
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = $e->getMessage();
        unset($_SESSION['user_id'], $_SESSION['agent_id'], $_SESSION['full_name'], $_SESSION['role'], $_SESSION['lob'], $_SESSION['activity_session_id']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(APP_NAME) ?> | Workforce Portal</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-body network-login">
<script src="/assets/ambient.js" defer></script>
<header class="login-utility">
    <a class="brand-lockup" href="/login.php">
        <span class="brand-mark">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 3a9 9 0 0 1 9 9"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
        </span>
        <div>
            <strong class="brand-name">ORION</strong>
            <span class="brand-sub">Workforce Intelligence</span>
        </div>
    </a>
    <div class="utility-actions">
        <time id="workspaceClock" class="date-chip">--:--:-- IST</time>
        <button type="button" class="btn secondary" id="motionToggle" aria-pressed="false">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
            Pause motion
        </button>
    </div>
</header>
<main class="login-shell">
    <section class="login-hero">
        <div class="login-copy">
            <span class="portal-tag">ONE WORKSPACE &middot; EVERY TEAM</span>
            <h1><span class="gradient-text">Connected teams.</span><br>Exceptional performance.</h1>
            <p>Your people, realtime activity and operational insights together in one unified dashboard. A crystal-clear view of every workday.</p>
        </div>
        <div class="signal-panel">
            <div class="signal-heading">
                <strong><i class="signal-dot"></i>Live Workforce Status</strong>
                <span class="signal-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
            </div>
            <div class="signal-metrics">
                <div><span>LIVE VISIBILITY</span><strong>2s Auto-Pulse</strong></div>
                <div><span>ACTIVE TEAMS</span><strong>3 Core LOBs</strong></div>
                <div><span>SECURITY</span><strong>Enterprise RBAC</strong></div>
            </div>
        </div>
        <div class="feature-grid">
            <article>
                <div class="feature-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div>
                    <h2>Realtime Monitoring</h2>
                    <p>Live agent availability, break times and session statuses updated instantly.</p>
                </div>
            </article>
            <article>
                <div class="feature-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="8"/><rect x="13" y="6" width="3" height="12"/><rect x="19" y="13" width="3" height="5"/></svg>
                </div>
                <div>
                    <h2>Analytics &amp; Reports</h2>
                    <p>In-depth activity logs, session histories and instant one-click CSV exports.</p>
                </div>
            </article>
            <article>
                <div class="feature-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <h2>Multi-LOB Routing</h2>
                    <p>Dynamic assignments for Sales, Collection and Backend operations.</p>
                </div>
            </article>
            <article>
                <div class="feature-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div>
                    <h2>Role-Based Console</h2>
                    <p>Dedicated modern interfaces tailored for Agents, Supervisors and Admins.</p>
                </div>
            </article>
        </div>
    </section>
    <section class="login-card" aria-labelledby="signinHeading">
        <div class="signin-emblem" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <div class="login-card-head">
            <h2 id="signinHeading">Welcome back</h2>
            <p class="muted">Sign in to access your Orion workspace</p>
        </div>
        <?php if ($error): ?>
            <div class="alert danger" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= e($error) ?>
            </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div>
                <label for="agent_id">
                    <span>User ID</span>
                    <span class="field-hint">WORK ACCOUNT</span>
                </label>
                <input id="agent_id" name="agent_id" placeholder="e.g. AGENT-101" autocomplete="username" required autofocus>
            </div>
            <div>
                <label for="password">Password</label>
                <div class="password-field">
                    <input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    <button id="passwordToggle" type="button" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>
            <p class="assignment-note">Your role &amp; assigned LOB will automatically route you to your workspace.</p>
            <button class="btn primary full login-submit" type="submit">
                <span>Sign in to workspace</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </button>
        </form>
        <div class="signin-footer">
            <span>Need assistance or password reset?</span>
            <strong>Contact your IT Administrator</strong>
        </div>
        <p class="login-footnote">&copy; <?= date('Y') ?> Orion IT Services Pvt. Ltd. All rights reserved.</p>
    </section>
</main>
<footer class="login-bottom">
    <span>ORION WORKFORCE MANAGEMENT &middot; ENTERPRISE SUITE</span>
    <span>Sales <b>&middot;</b> Collection <b>&middot;</b> Backend</span>
</footer>
</body>
</html>