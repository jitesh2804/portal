<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('admin', 'supervisor');

$pdo = db();

$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');
$agentId = trim($_GET['agent_id'] ?? '');
$type = ($_GET['type'] ?? '') === 'sessions' ? 'sessions' : 'activity';
foreach ([$from, $to] as $date) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        http_response_code(400);
        exit('Invalid report date. Use YYYY-MM-DD.');
    }
}
if ($from > $to) {
    http_response_code(400);
    exit('From date must be on or before To date.');
}

if ($type === 'activity') {
$sql = "
WITH sessions AS (
    SELECT s.id,
           s.user_id,
           s.login_at,
           COALESCE(s.logout_at, s.last_seen) AS effective_logout,
           GREATEST(0, EXTRACT(EPOCH FROM (COALESCE(s.logout_at, s.last_seen) - s.login_at)))::bigint AS login_seconds
      FROM agent_sessions s
     WHERE s.login_at >= CAST(:from_date AS date)
       AND s.login_at < (CAST(:to_date AS date) + INTERVAL '1 day')
),
activity AS (
    SELECT al.user_id,
           SUM(GREATEST(0, EXTRACT(EPOCH FROM (LEAST(COALESCE(al.end_time, s.effective_logout), s.effective_logout) - al.start_time))))
               FILTER (WHERE al.activity_type='IDLE') AS idle_seconds,
           SUM(GREATEST(0, EXTRACT(EPOCH FROM (LEAST(COALESCE(al.end_time, s.effective_logout), s.effective_logout) - al.start_time))))
               FILTER (WHERE al.activity_type='PAUSE') AS pause_seconds
      FROM activity_log al
      JOIN sessions s ON s.id = al.session_id
     GROUP BY al.user_id
),
pause_totals AS (
    SELECT al.user_id,
           COALESCE(pc.code_name,'Unknown') AS code_name,
           SUM(GREATEST(0, EXTRACT(EPOCH FROM (LEAST(COALESCE(al.end_time, s.effective_logout), s.effective_logout) - al.start_time))))::bigint AS seconds
      FROM activity_log al
      JOIN sessions s ON s.id = al.session_id
      LEFT JOIN pause_codes pc ON pc.id = al.pause_code_id
     WHERE al.activity_type='PAUSE'
     GROUP BY al.user_id, COALESCE(pc.code_name,'Unknown')
),
pause_detail AS (
    SELECT user_id,
           STRING_AGG(
               code_name || ': ' ||
               LPAD((seconds / 3600)::text, 2, '0') || ':' ||
               LPAD(((seconds % 3600) / 60)::text, 2, '0') || ':' ||
               LPAD((seconds % 60)::text, 2, '0'),
               ' | ' ORDER BY code_name
           ) AS pause_breakdown
      FROM pause_totals
     GROUP BY user_id
)
SELECT u.agent_id,
       u.full_name,
       u.lob,
       COUNT(s.id)::int AS session_count,
       COALESCE(SUM(s.login_seconds),0)::bigint AS login_seconds,
       COALESCE(a.idle_seconds,0)::bigint AS idle_seconds,
       COALESCE(a.pause_seconds,0)::bigint AS pause_seconds,
       COALESCE(pd.pause_breakdown,'') AS pause_breakdown
  FROM users u
  LEFT JOIN sessions s ON s.user_id=u.id
  LEFT JOIN activity a ON a.user_id=u.id
  LEFT JOIN pause_detail pd ON pd.user_id=u.id
 WHERE u.role='agent'
";

$params = [
    ':from_date' => $from,
    ':to_date' => $to,
];

if ($agentId !== '') {
    $sql .= " AND u.agent_id ILIKE :agent_id ";
    $params[':agent_id'] = '%' . $agentId . '%';
}

$sql .= "
 GROUP BY u.id, u.agent_id, u.full_name, a.idle_seconds, a.pause_seconds, pd.pause_breakdown
 ORDER BY u.agent_id
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="agent_activity_report_'.$from.'_to_'.$to.'.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Agent ID','Name','Current assigned LOB','Sessions','Login Duration','Idle Duration','Break Duration','Pause Breakdown']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['agent_id'],
            $r['full_name'],
            $r['lob'] ?? 'Unassigned',
            $r['session_count'],
            formatSeconds((int)$r['login_seconds']),
            formatSeconds((int)$r['idle_seconds']),
            formatSeconds((int)$r['pause_seconds']),
            $r['pause_breakdown']
        ]);
    }
    fclose($out);
    exit;
}
} else {
    $sql = "SELECT s.id, u.agent_id, u.full_name, s.lob, s.login_at, s.logout_at, s.last_seen,
                   s.login_ip,
                   CASE WHEN s.logout_at IS NOT NULL THEN 'Logged out'
                        WHEN s.last_seen >= NOW() - INTERVAL '90 seconds' THEN 'Online'
                        ELSE 'Disconnected' END AS session_status,
                   GREATEST(0, EXTRACT(EPOCH FROM (COALESCE(s.logout_at, s.last_seen) - s.login_at)))::bigint AS login_seconds
              FROM agent_sessions s JOIN users u ON u.id = s.user_id
             WHERE u.role = 'agent' AND s.login_at >= CAST(:from_date AS date)
               AND s.login_at < CAST(:to_date AS date) + INTERVAL '1 day'";
    $params = [':from_date' => $from, ':to_date' => $to];
    if ($agentId !== '') {
        $sql .= ' AND u.agent_id ILIKE :agent_id';
        $params[':agent_id'] = '%' . $agentId . '%';
    }
    $sql .= ' ORDER BY s.login_at DESC, s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="login_logout_report_'.$from.'_to_'.$to.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Session ID', 'Agent ID', 'Name', 'Login LOB', 'Login time', 'Logout time', 'Last seen', 'Duration', 'Status', 'Login IP']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['agent_id'], $r['full_name'], $r['lob'] ?? 'Unassigned', $r['login_at'], $r['logout_at'] ?? '',
                $r['last_seen'], formatSeconds((int)$r['login_seconds']), $r['session_status'], $r['login_ip']]);
        }
        fclose($out);
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reports</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/_nav.php'; ?>
<main class="admin-main">
    <div class="page-head">
        <div>
            <span class="eyebrow">REPORTING</span>
            <h1><?= $type === 'sessions' ? 'Login / Logout Report' : 'Agent Activity Report' ?></h1>
            <p class="muted">Filter by session login date and agent ID. Export either report to CSV.</p>
        </div>
    </div>

    <section class="panel">
        <nav class="report-tabs" aria-label="Report type">
            <a class="btn <?= $type === 'activity' ? 'primary' : 'secondary' ?>" href="?<?= e(http_build_query(['type'=>'activity','from'=>$from,'to'=>$to,'agent_id'=>$agentId])) ?>">Activity summary</a>
            <a class="btn <?= $type === 'sessions' ? 'primary' : 'secondary' ?>" href="?<?= e(http_build_query(['type'=>'sessions','from'=>$from,'to'=>$to,'agent_id'=>$agentId])) ?>">Login / Logout</a>
        </nav>
        <form method="get" class="form-grid four">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
            <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
            <div><label>Agent ID</label><input name="agent_id" value="<?= e($agentId) ?>" placeholder="Optional"></div>
            <div class="form-button">
                <button class="btn primary">Fetch Report</button>
                <button class="btn secondary" name="export" value="csv">Export CSV</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <?php if ($type === 'sessions'): ?>
        <div class="panel-head"><div><h2>Session history</h2><p class="muted">One row per login. Missing logout means no logout was recorded; duration ends at last seen.</p></div><span class="count-chip"><?= count($rows) ?> sessions</span></div>
        <div class="table-wrap"><table><thead><tr><th>Session</th><th>Agent ID</th><th>Name</th><th>Login LOB</th><th>Login time</th><th>Logout time</th><th>Last seen</th><th>Duration</th><th>Status</th><th>Login IP</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td><?= (int)$r['id'] ?></td><td><strong><?= e($r['agent_id']) ?></strong></td><td><?= e($r['full_name']) ?></td>
            <td><?= e($r['lob'] ?? 'Unassigned') ?></td>
            <td><?= e(date('d M Y H:i:s', strtotime($r['login_at']))) ?></td>
            <td><?= $r['logout_at'] ? e(date('d M Y H:i:s', strtotime($r['logout_at']))) : 'Not recorded' ?></td>
            <td><?= e(date('d M Y H:i:s', strtotime($r['last_seen']))) ?></td><td><?= e(formatSeconds((int)$r['login_seconds'])) ?></td>
            <td><span class="badge <?= $r['session_status'] === 'Online' ? 'success' : ($r['session_status'] === 'Disconnected' ? 'warning' : 'danger') ?>"><?= e($r['session_status']) ?></span></td><td><?= e($r['login_ip'] ?? '-') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="center empty-state muted">No sessions found for these filters.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Agent ID</th><th>Name</th><th>Current assigned LOB</th><th>Sessions</th><th>Login</th><th>Idle</th><th>Break</th><th>Pause Breakdown</th></tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="8" class="center empty-state muted">No agents found for these filters.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['agent_id']) ?></strong></td>
                        <td><?= e($r['full_name']) ?></td>
                        <td><?= e($r['lob'] ?? 'Unassigned') ?></td>
                        <td><?= (int)$r['session_count'] ?></td>
                        <td><?= e(formatSeconds((int)$r['login_seconds'])) ?></td>
                        <td><?= e(formatSeconds((int)$r['idle_seconds'])) ?></td>
                        <td><?= e(formatSeconds((int)$r['pause_seconds'])) ?></td>
                        <td><?= e($r['pause_breakdown'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
