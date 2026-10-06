<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('agent');

$userId = (int)$_SESSION['user_id'];
$yearOptionsStmt = db()->prepare("
    SELECT leave_year
      FROM agent_leave_balances
     WHERE user_id = :user_id
     ORDER BY leave_year DESC
");
$yearOptionsStmt->execute([':user_id' => $userId]);
$years = array_map('intval', $yearOptionsStmt->fetchAll(PDO::FETCH_COLUMN));
$selectedYear = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
if ($selectedYear === false) {
    http_response_code(400);
    exit('Choose a valid leave year.');
}
if (!in_array((int)$selectedYear, $years, true)) {
    $years[] = (int)$selectedYear;
    rsort($years);
}

$stmt = db()->prepare("
    SELECT leave_year, total_entitlement,
           january, february, march, april, may, june,
           july, august, september, october, november, december,
           source_name, employee_status, process_name, date_of_joining, tenure_days, uploaded_at
      FROM agent_leave_balances
     WHERE user_id = :user_id AND leave_year = :leave_year
");
$stmt->execute([':user_id' => $userId, ':leave_year' => $selectedYear]);
$leave = $stmt->fetch() ?: null;
$monthColumns = [
    'January' => 'january',
    'February' => 'february',
    'March' => 'march',
    'April' => 'april',
    'May' => 'may',
    'June' => 'june',
    'July' => 'july',
    'August' => 'august',
    'September' => 'september',
    'October' => 'october',
    'November' => 'november',
    'December' => 'december',
];
$used = 0.0;
if ($leave) {
    foreach ($monthColumns as $column) {
        $used += (float)$leave[$column];
    }
}
$entitlement = (float)($leave['total_entitlement'] ?? 0);
$pending = max(0, $entitlement - $used);
$overused = max(0, $used - $entitlement);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Leave Balance</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="agent-body">
<script src="/assets/ambient.js" defer></script>
<header class="topbar">
    <div class="brand-lockup">
        <span class="brand-mark">AP</span>
        <div><div class="brand-name">Activity Portal</div><div class="brand-sub">Your daily workspace</div></div>
    </div>
    <div class="top-actions">
        <div class="user-chip">
            <strong><?= e($_SESSION['full_name'] ?? '') ?></strong>
            <span><?= e($_SESSION['agent_id'] ?? '') ?> &middot; My leave</span>
        </div>
        <a class="btn secondary" href="/agent/dashboard.php">Back to Dashboard</a>
        <a class="btn danger ghost" href="/logout.php">Logout</a>
    </div>
</header>

<main class="page">
    <div class="page-head">
        <div>
            <span class="eyebrow">LEAVE OVERVIEW</span>
            <h1>My Leave Balance<span class="title-dot">.</span></h1>
            <p class="muted">Your yearly entitlement and month-wise leave usage.</p>
        </div>
        <form method="get" class="leave-year-filter">
            <label for="leaveYear">Leave year</label>
            <select id="leaveYear" name="year">
                <?php foreach ($years as $yearOption): ?><option value="<?= $yearOption ?>" <?= $yearOption === (int)$selectedYear ? 'selected' : '' ?>><?= $yearOption ?></option><?php endforeach; ?>
            </select>
            <button class="btn secondary">View</button>
        </form>
    </div>

    <?php if (!$leave): ?>
        <section class="panel leave-empty">
            <span class="empty-icon" aria-hidden="true">&#9636;</span>
            <h2>Leave balance not uploaded yet</h2>
            <p class="muted">Your admin will upload your annual entitlement and monthly leave record.</p>
        </section>
    <?php else: ?>
        <section class="cards four leave-summary">
            <div class="stat-card"><span>Total entitlement</span><strong><?= e(number_format($entitlement, 2, '.', '')) ?></strong><small>Leave days assigned for <?= (int)$leave['leave_year'] ?></small></div>
            <div class="stat-card tone-amber"><span>Leave used</span><strong><?= e(number_format($used, 2, '.', '')) ?></strong><small>Total of January–December</small></div>
            <div class="stat-card tone-green"><span>Pending leave</span><strong><?= e(number_format($pending, 2, '.', '')) ?></strong><small>Available to use</small></div>
            <div class="stat-card <?= $overused > 0 ? 'tone-amber' : '' ?>"><span>Over entitlement</span><strong><?= e(number_format($overused, 2, '.', '')) ?></strong><small>Used above yearly entitlement</small></div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div><h2><?= (int)$leave['leave_year'] ?> monthly leave usage</h2><p class="muted">One row for each month, as uploaded by your admin.</p></div>
            </div>
            <div class="table-wrap">
                <table class="agent-leave-table">
                    <thead><tr><th>Month</th><th>Leave used</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthColumns as $month => $column): ?>
                        <tr><td><?= e($month) ?></td><td><?= e(number_format((float)$leave[$column], 2, '.', '')) ?> days</td></tr>
                    <?php endforeach; ?>
                    <tr class="leave-total-row"><th>Total used</th><th><?= e(number_format($used, 2, '.', '')) ?> days</th></tr>
                    </tbody>
                </table>
            </div>
            <?php if ($overused > 0): ?><p class="alert danger leave-overused">You have used <?= e(number_format($overused, 2, '.', '')) ?> days above your annual entitlement.</p><?php endif; ?>
            <?php if ($leave['uploaded_at']): ?><p class="muted small leave-updated">Last updated <?= e(date('d M Y H:i', strtotime($leave['uploaded_at']))) ?></p><?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
