<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('admin');

$pdo = db();
expireExpiredAgentSessions($pdo);

$timeoutSeconds = (int)AGENT_SESSION_TIMEOUT;
$statsStmt = $pdo->prepare("
    WITH session_window AS (
        SELECT NOW() - ({$timeoutSeconds} * INTERVAL '1 second') AS cutoff
    )
    SELECT
        (SELECT COUNT(*) FROM users WHERE role='agent' AND is_active=TRUE) AS total_agents,
        (SELECT COUNT(*) FROM agent_sessions
          WHERE logout_at IS NULL AND login_at >= (SELECT cutoff FROM session_window)) AS online_agents,
        (SELECT COUNT(*) FROM activity_log al
          JOIN agent_sessions s ON s.id=al.session_id
         WHERE al.activity_type='PAUSE'
           AND al.end_time IS NULL
           AND s.logout_at IS NULL
           AND s.login_at >= (SELECT cutoff FROM session_window)) AS on_break,
        (SELECT COUNT(*) FROM pause_codes WHERE is_active=TRUE) AS pause_codes
");
$statsStmt->execute();
$stats = $statsStmt->fetch();

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/_nav.php'; ?>
<main class="admin-main">
    <div class="page-head">
        <div>
            <span class="eyebrow">ADMIN CONSOLE</span>
            <h1>Workspace overview<span class="title-dot">.</span></h1>
            <p class="muted">Live overview of agent availability and breaks.</p>
        </div>
        <a class="btn secondary" href="/admin/report.php">View reports &nearr;</a>
    </div>

    <section class="overview-banner">
        <div><span class="eyebrow">YOUR TEAM, AT A GLANCE</span><h2>Great work starts<br>with a connected team.</h2><p>Keep an eye on availability. Keep the day moving.</p><a class="btn primary" href="/admin/users.php">Manage your team <span aria-hidden="true">&rarr;</span></a></div>
        <div class="team-orbit"><div class="orbit-core"><strong data-live-count="online"><?= (int)$stats['online_agents'] ?></strong><span>active logins</span></div><span class="orbit-label"><i></i> Team activity</span></div>
    </section>
    <section class="cards four">
        <div class="stat-card"><span>Active Agents <b class="metric-icon" aria-hidden="true">&#9823;</b></span><strong><?= (int)$stats['total_agents'] ?></strong><small>Enabled team members</small></div>
        <div class="stat-card tone-green"><span>Active Logins <b class="metric-icon" aria-hidden="true">&#9673;</b></span><strong data-live-count="online"><?= (int)$stats['online_agents'] ?></strong><small>Signed in within the 9-hour session window</small></div>
        <div class="stat-card tone-amber"><span>On Break <b class="metric-icon" aria-hidden="true">&#9208;</b></span><strong data-live-count="pause"><?= (int)$stats['on_break'] ?></strong><small>Taking a moment to recharge</small></div>
        <div class="stat-card"><span>Active Pause Codes <b class="metric-icon" aria-hidden="true">&#9783;</b></span><strong><?= (int)$stats['pause_codes'] ?></strong><small>Available break categories</small></div>
    </section>

    <?php include __DIR__ . '/_live.php'; ?>
</main>

</body>
</html>
