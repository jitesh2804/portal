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
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(APP_NAME) ?> | Workforce Portal</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-body network-login">
<script src="/assets/ambient.js" defer></script>
<header class="login-utility"><a class="brand-lockup" href="/login.php"><span class="brand-mark">O</span><span><strong class="brand-name">ORION</strong><span class="brand-sub">Workforce workspace</span></span></a><div class="utility-actions"><time id="workspaceClock" class="date-chip"></time><button type="button" class="btn secondary" id="motionToggle" aria-pressed="false">Pause motion</button></div></header>
<main class="login-shell">
    <section class="login-hero">
        <div class="login-copy">
            <span class="portal-tag">ONE WORKSPACE. EVERY TEAM.</span>
            <h1><span class="gradient-text">Connected teams.</span><br>Exceptional work.</h1>
            <p>Your people, activity and insights together. A clearer view of every workday, from the first login to the final sign-off.</p>
        </div>
        <div class="signal-panel"><div class="signal-heading"><strong><i class="signal-dot"></i>Workforce activity, in focus</strong><span class="signal-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span></div><div class="signal-metrics"><div><span>LIVE VISIBILITY</span><strong>2-second updates</strong></div><div><span>YOUR TEAMS</span><strong>3 business lines</strong></div><div><span>ACCESS CONTROL</span><strong>Role-based access</strong></div></div></div>
        <div class="feature-grid">
            <article><span class="feature-icon" aria-hidden="true">&#9678;</span><div><h2>Realtime activity</h2><p>Availability, breaks and session status in one view.</p></div></article>
            <article><span class="feature-icon" aria-hidden="true">&#9636;</span><div><h2>Reports that deliver</h2><p>Login history, activity summaries and CSV exports.</p></div></article>
            <article><span class="feature-icon" aria-hidden="true">&#9783;</span><div><h2>Built around your teams</h2><p>Sales, Collection and Backend. Assigned by your admin or supervisor.</p></div></article>
            <article><span class="feature-icon" aria-hidden="true">&#9672;</span><div><h2>The right access</h2><p>Dedicated workspaces for agents, supervisors and admins.</p></div></article>
        </div>
    </section>
    <section class="login-card" aria-labelledby="signinHeading">
        <div class="signin-emblem" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/></svg></div>
        <div class="login-card-head"><h2 id="signinHeading">Welcome back</h2><p class="muted">Sign in to your Orion workspace.</p></div>
        <?php if ($error): ?><div class="alert danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div><label for="agent_id">User ID <span class="field-hint">YOUR WORK ACCOUNT</span></label><input id="agent_id" name="agent_id" placeholder="Enter your user ID" autocomplete="username" required autofocus></div>
            <div><label for="password">Password</label><div class="password-field"><input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button id="passwordToggle" type="button" aria-label="Show password" aria-pressed="false">Show</button></div></div>
            <p class="assignment-note">Your assigned role and LOB take you to the right workspace automatically.</p>
            <button class="btn primary full login-submit" type="submit">Sign in to workspace <span aria-hidden="true">&rarr;</span></button>
        </form>
        <div class="signin-footer"><span>Need access or a password reset?</span><strong>Contact your administrator.</strong></div>
        <p class="login-footnote">&copy; <?= date('Y') ?> Orion IT Services Pvt. Ltd.</p>
    </section>
</main>
<footer class="login-bottom"><span>ORION / WORKFORCE INTELLIGENCE</span><span>Sales <b>&middot;</b> Collection <b>&middot;</b> Backend</span></footer>
</body>
</html>