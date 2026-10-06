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
$view = (string)($_GET['view'] ?? 'total_cl_el_use');
if (!in_array($view, ['total_cl_el_use', 'dashboard'], true)) {
    http_response_code(400);
    exit('Choose a valid leave view.');
}
if (!in_array((int)$selectedYear, $years, true)) {
    $years[] = (int)$selectedYear;
    rsort($years);
}

$stmt = db()->prepare("
    SELECT leave_year, grand_total,
           january, february, march, april, may, june,
           july, august, september, october, november, december,
           source_name, employee_status, process_name, date_of_joining, tenure_days, uploaded_at,
           month_count, total_cl, total_el, cl_used, el_used,
           cl_in_bucket, el_in_bucket, total_leaves_in_bucket
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
$grandTotal = (float)($leave['grand_total'] ?? 0);
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
            <p class="muted">Your leave summary and month-wise leave usage.</p>
        </div>
        <form method="get" class="leave-year-filter leave-view-filter">
            <label for="leaveView">View</label>
            <select id="leaveView" name="view">
                <option value="total_cl_el_use" <?= $view === 'total_cl_el_use' ? 'selected' : '' ?>>Total CL/EL Use</option>
                <option value="dashboard" <?= $view === 'dashboard' ? 'selected' : '' ?>>Dashboard</option>
            </select>
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
            <p class="muted">Your admin will upload your leave record.</p>
        </section>
    <?php else: ?>
        <?php if ($view === 'dashboard'): ?>
        <?php
        $dashboardColumns = [
            'Name' => 'source_name',
            'Status' => 'employee_status',
            'Process' => 'process_name',
            'DOJ' => 'date_of_joining',
            'Tenure' => 'tenure_days',
            'Month' => 'month_count',
            'Total CL' => 'total_cl',
            'Total EL' => 'total_el',
            'CL Used' => 'cl_used',
            'EL Used' => 'el_used',
            'CL in Bucket' => 'cl_in_bucket',
            'EL in Bucket' => 'el_in_bucket',
            'Total Leaves in Bucket' => 'total_leaves_in_bucket',
        ];
        ?>
        <section class="panel">
            <div class="panel-head">
                <div><h2><?= (int)$leave['leave_year'] ?> leave dashboard</h2><p class="muted">Your CL/EL balances and bucket details from the uploaded sheet.</p></div>
            </div>
            <div class="table-wrap">
                <table class="leave-dashboard-table">
                    <thead><tr><?php foreach ($dashboardColumns as $label => $_column): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
                    <tbody><tr>
                        <?php foreach ($dashboardColumns as $label => $column): ?>
                            <?php
                            $value = $leave[$column];
                            if ($column === 'source_name' && ($value === null || $value === '')) {
                                $value = $_SESSION['full_name'] ?? '';
                            } elseif ($column === 'date_of_joining' && $value !== null) {
                                $value = date('d M Y', strtotime($value));
                            } elseif ($column === 'tenure_days' && $value !== null) {
                                $value = (int)$value . ' days';
                            } elseif ($column === 'month_count' && $value !== null) {
                                $value = (string)(int)$value;
                            } elseif ($value !== null && in_array($column, ['total_cl', 'total_el', 'cl_used', 'el_used', 'cl_in_bucket', 'el_in_bucket', 'total_leaves_in_bucket'], true)) {
                                $value = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
                            }
                            ?>
                            <td><?= $value === null || $value === '' ? '&mdash;' : e((string)$value) ?></td>
                        <?php endforeach; ?>
                    </tr></tbody>
                </table>
            </div>
            <?php if ($leave['uploaded_at']): ?><p class="muted small leave-updated">Last updated <?= e(date('d M Y H:i', strtotime($leave['uploaded_at']))) ?></p><?php endif; ?>
        </section>
        <?php else: ?>
        <section class="cards three leave-summary">
            <div class="stat-card tone-amber"><span>Grand Total</span><strong><?= e(number_format($grandTotal, 2, '.', '')) ?></strong><small>Leave used in <?= (int)$leave['leave_year'] ?></small></div>
            <div class="stat-card"><span>DOJ</span><strong><?= $leave['date_of_joining'] ? e(date('d M Y', strtotime($leave['date_of_joining']))) : '&mdash;' ?></strong><small>Date of Joining</small></div>
            <div class="stat-card tone-green"><span>Tenure</span><strong><?= $leave['tenure_days'] === null ? '&mdash;' : (int)$leave['tenure_days'] . ' days' ?></strong><small>Length of service</small></div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div><h2><?= (int)$leave['leave_year'] ?> leave record</h2><p class="muted">Month-wise leave usage from the uploaded sheet.</p></div>
            </div>
            <div class="table-wrap">
                <table class="agent-leave-table">
                    <thead><tr><th>Month</th><th>Leave used</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthColumns as $month => $column): ?>
                        <tr><td><?= e($month) ?></td><td><?= e(number_format((float)$leave[$column], 2, '.', '')) ?> days</td></tr>
                    <?php endforeach; ?>
                    <tr class="leave-total-row"><th>Grand Total</th><th><?= e(number_format($grandTotal, 2, '.', '')) ?> days</th></tr>
                    </tbody>
                </table>
            </div>
            <?php if ($leave['uploaded_at']): ?><p class="muted small leave-updated">Last updated <?= e(date('d M Y H:i', strtotime($leave['uploaded_at']))) ?></p><?php endif; ?>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
