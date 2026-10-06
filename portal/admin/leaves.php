<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('admin');

$pdo = db();
$months = [
    'January' => ['january', 'jan'],
    'February' => ['february', 'feb'],
    'March' => ['march', 'mar'],
    'April' => ['april', 'apr'],
    'May' => ['may'],
    'June' => ['june', 'jun'],
    'July' => ['july', 'jul'],
    'August' => ['august', 'aug'],
    'September' => ['september', 'sep', 'sept'],
    'October' => ['october', 'oct'],
    'November' => ['november', 'nov'],
    'December' => ['december', 'dec'],
];

function leaveHeaderKey(string $header): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]/i', '', trim($header)));
}

function leaveNumber(string $value, string $label, int $rowNumber, bool $blankIsZero = false): float
{
    $value = trim($value);
    if ($value === '' && $blankIsZero) {
        return 0.0;
    }
    if ($value === '' || !is_numeric($value)) {
        throw new RuntimeException("Row {$rowNumber}: {$label} must be a number.");
    }

    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > 999999.99) {
        throw new RuntimeException("Row {$rowNumber}: {$label} must be between 0 and 999999.99.");
    }
    return round($number, 2);
}

function leaveDateOfJoining(string $value, int $rowNumber): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d');
    } catch (Throwable) {
        throw new RuntimeException("Row {$rowNumber}: DOJ is not a valid date.");
    }
}

if (($_GET['template'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="agent_leave_upload_template.csv"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('Could not create the leave upload template.');
    }
    fputcsv($out, ['EMP ID', 'Name', 'Status', 'Process', 'DOJ', 'Tenure', ...array_keys($months), 'Grand Total', 'Total Entitlement']);
    fclose($out);
    exit;
}

$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
if ($year === false) {
    http_response_code(400);
    exit('Choose a valid leave year.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        if (($_POST['action'] ?? '') !== 'upload') {
            throw new RuntimeException('Invalid leave upload action.');
        }

        $uploadYear = filter_var($_POST['leave_year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if ($uploadYear === false) {
            throw new RuntimeException('Choose a valid leave year.');
        }
        if (!isset($_FILES['leave_file']) || $_FILES['leave_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Choose a CSV or TSV leave file to upload.');
        }
        if ($_FILES['leave_file']['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('The leave file must be smaller than 5 MB.');
        }
        if (!is_uploaded_file($_FILES['leave_file']['tmp_name'])) {
            throw new RuntimeException('The selected file upload could not be verified.');
        }

        $handle = fopen($_FILES['leave_file']['tmp_name'], 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read the uploaded leave file.');
        }
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('The uploaded leave file is empty.');
        }
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
        $delimiter = ',';
        foreach ([",", "\t", ";"] as $candidate) {
            if (count(str_getcsv($firstLine, $candidate)) > count(str_getcsv($firstLine, $delimiter))) {
                $delimiter = $candidate;
            }
        }
        rewind($handle);
        $headers = fgetcsv($handle, null, $delimiter);
        if ($headers === false) {
            fclose($handle);
            throw new RuntimeException('Could not read the leave file headings.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]) ?? (string)$headers[0];
        $headerIndexes = [];
        foreach ($headers as $index => $header) {
            $headerIndexes[leaveHeaderKey((string)$header)] = $index;
        }

        $findHeader = static function (array $aliases) use ($headerIndexes): ?int {
            foreach ($aliases as $alias) {
                $key = leaveHeaderKey($alias);
                if (array_key_exists($key, $headerIndexes)) {
                    return $headerIndexes[$key];
                }
            }
            return null;
        };
        $idIndex = $findHeader(['EMP ID', 'Employee ID', 'Agent ID', 'User ID']);
        $entitlementIndex = $findHeader(['Total Entitlement', 'Annual Leave Entitlement', 'Total Leave']);
        $grandTotalIndex = $findHeader(['Grand Total', 'Total Used', 'Used Total']);
        if ($idIndex === null || $entitlementIndex === null || $grandTotalIndex === null) {
            fclose($handle);
            throw new RuntimeException('The file must include EMP ID, Grand Total and Total Entitlement headings.');
        }

        $monthIndexes = [];
        foreach ($months as $month => $aliases) {
            $monthIndex = $findHeader($aliases);
            if ($monthIndex === null) {
                fclose($handle);
                throw new RuntimeException("The file is missing the {$month} column.");
            }
            $monthIndexes[$month] = $monthIndex;
        }

        $agentRows = $pdo->query("SELECT id, agent_id FROM users WHERE role = 'agent'")->fetchAll();
        $exactAgentIds = [];
        $aliasAgentIds = [];
        foreach ($agentRows as $agent) {
            $agentKey = strtoupper(trim($agent['agent_id']));
            $exactAgentIds[$agentKey] = (int)$agent['id'];
            $alias = preg_replace('/^OIT(?=\d+$)/', '', $agentKey) ?? $agentKey;
            $aliasAgentIds[$alias][] = (int)$agent['id'];
        }

        $nameIndex = $findHeader(['Name', 'Employee Name', 'Full Name']);
        $statusIndex = $findHeader(['Status']);
        $processIndex = $findHeader(['Process']);
        $dojIndex = $findHeader(['DOJ', 'Date Of Joining']);
        $tenureIndex = $findHeader(['Tenure', 'Tenure Days']);
        $rows = [];
        $seenAgents = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            $rowNumber++;
            if ($row === [null] || count(array_filter($row, static fn($value): bool => trim((string)$value) !== '')) === 0) {
                continue;
            }
            $sourceAgentId = strtoupper(trim((string)($row[$idIndex] ?? '')));
            if ($sourceAgentId === '') {
                throw new RuntimeException("Row {$rowNumber}: EMP ID is required.");
            }

            $portalUserId = $exactAgentIds[$sourceAgentId] ?? null;
            if ($portalUserId === null) {
                $alias = preg_replace('/^OIT(?=\d+$)/', '', $sourceAgentId) ?? $sourceAgentId;
                $matches = $aliasAgentIds[$alias] ?? [];
                if (count($matches) !== 1) {
                    throw new RuntimeException("Row {$rowNumber}: EMP ID {$sourceAgentId} does not match exactly one agent account.");
                }
                $portalUserId = $matches[0];
            }
            if (isset($seenAgents[$portalUserId])) {
                throw new RuntimeException("Row {$rowNumber}: agent {$sourceAgentId} appears more than once.");
            }
            $seenAgents[$portalUserId] = true;

            $monthValues = [];
            $usedTotal = 0.0;
            foreach ($monthIndexes as $month => $index) {
                $value = leaveNumber((string)($row[$index] ?? ''), $month, $rowNumber, true);
                $monthValues[strtolower($month)] = number_format($value, 2, '.', '');
                $usedTotal += $value;
            }
            $reportedTotal = leaveNumber((string)($row[$grandTotalIndex] ?? ''), 'Grand Total', $rowNumber);
            if (abs(round($usedTotal, 2) - $reportedTotal) > 0.01) {
                throw new RuntimeException("Row {$rowNumber}: Grand Total must equal the sum of the monthly leave values.");
            }

            $optionalText = static function (?int $index) use ($row): ?string {
                if ($index === null) {
                    return null;
                }
                $value = trim((string)($row[$index] ?? ''));
                return $value === '' ? null : $value;
            };
            $tenure = $optionalText($tenureIndex);
            if ($tenure !== null && (!ctype_digit($tenure) || (int)$tenure > 2147483647)) {
                throw new RuntimeException("Row {$rowNumber}: Tenure must be a whole number of days.");
            }

            $rows[] = [
                'user_id' => $portalUserId,
                'leave_year' => $uploadYear,
                'total_entitlement' => number_format(leaveNumber((string)($row[$entitlementIndex] ?? ''), 'Total Entitlement', $rowNumber), 2, '.', ''),
                'months' => $monthValues,
                'source_name' => $optionalText($nameIndex),
                'employee_status' => $optionalText($statusIndex),
                'process_name' => $optionalText($processIndex),
                'date_of_joining' => leaveDateOfJoining($optionalText($dojIndex) ?? '', $rowNumber),
                'tenure_days' => $tenure === null ? null : (int)$tenure,
            ];
        }
        fclose($handle);
        if ($rows === []) {
            throw new RuntimeException('The leave file has no agent rows to import.');
        }

        $columns = implode(', ', array_map(static fn(string $month): string => strtolower($month), array_keys($months)));
        $monthPlaceholders = implode(', ', array_map(static fn(string $month): string => ':' . strtolower($month), array_keys($months)));
        $updates = ['total_entitlement = EXCLUDED.total_entitlement'];
        foreach (array_keys($months) as $month) {
            $column = strtolower($month);
            $updates[] = "{$column} = EXCLUDED.{$column}";
        }
        foreach (['source_name', 'employee_status', 'process_name', 'date_of_joining', 'tenure_days'] as $column) {
            $updates[] = "{$column} = EXCLUDED.{$column}";
        }
        $updates[] = 'uploaded_by = EXCLUDED.uploaded_by';
        $updates[] = 'uploaded_at = NOW()';
        $upsert = $pdo->prepare("
            INSERT INTO agent_leave_balances (
                user_id, leave_year, total_entitlement, {$columns},
                source_name, employee_status, process_name, date_of_joining, tenure_days, uploaded_by
            ) VALUES (
                :user_id, :leave_year, :total_entitlement, {$monthPlaceholders},
                :source_name, :employee_status, :process_name, :date_of_joining, :tenure_days, :uploaded_by
            )
            ON CONFLICT (user_id, leave_year) DO UPDATE SET " . implode(', ', $updates)
        );

        $pdo->beginTransaction();
        foreach ($rows as $leaveRow) {
            $params = [
                ':user_id' => $leaveRow['user_id'],
                ':leave_year' => $leaveRow['leave_year'],
                ':total_entitlement' => $leaveRow['total_entitlement'],
                ':source_name' => $leaveRow['source_name'],
                ':employee_status' => $leaveRow['employee_status'],
                ':process_name' => $leaveRow['process_name'],
                ':date_of_joining' => $leaveRow['date_of_joining'],
                ':tenure_days' => $leaveRow['tenure_days'],
                ':uploaded_by' => (int)$_SESSION['user_id'],
            ];
            foreach ($leaveRow['months'] as $month => $value) {
                $params[':' . $month] = $value;
            }
            $upsert->execute($params);
        }
        $pdo->commit();
        flash('success', count($rows) . " agent leave balances uploaded for {$uploadYear}.");
        redirect('/admin/leaves.php?year=' . $uploadYear);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
        $failedYear = filter_var($_POST['leave_year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        redirect('/admin/leaves.php?year=' . ($failedYear === false ? date('Y') : $failedYear));
    }
}

$stmt = $pdo->prepare("
    SELECT u.agent_id,
           COALESCE(b.source_name, u.full_name) AS name,
           b.employee_status,
           b.process_name,
           b.date_of_joining,
           b.tenure_days,
           b.total_entitlement,
           b.january, b.february, b.march, b.april, b.may, b.june,
           b.july, b.august, b.september, b.october, b.november, b.december
      FROM users u
      LEFT JOIN agent_leave_balances b
        ON b.user_id = u.id AND b.leave_year = :leave_year
     WHERE u.role = 'agent'
     ORDER BY u.agent_id
");
$stmt->execute([':leave_year' => $year]);
$agents = $stmt->fetchAll();
$flash = pullFlash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agent Leave Management</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/_nav.php'; ?>
<main class="admin-main">
    <div class="page-head">
        <div>
            <span class="eyebrow">PEOPLE OPERATIONS</span>
            <h1>Agent Leave Management</h1>
            <p class="muted">Upload each agent's yearly entitlement and month-wise leave usage.</p>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <div><h2>Upload leave sheet</h2><p class="muted">CSV/TSV only, up to 5 MB. Uploading an agent again replaces that agent's balance for the selected year.</p></div>
            <a class="btn secondary" href="/admin/leaves.php?template=csv">Download CSV template</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="form-grid four leave-upload-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="upload">
            <div><label for="leaveYear">Leave year</label><input id="leaveYear" type="number" name="leave_year" min="2000" max="2100" value="<?= (int)$year ?>" required></div>
            <div><label for="leaveFile">CSV / TSV file</label><input id="leaveFile" type="file" name="leave_file" accept=".csv,.tsv,text/csv,text/tab-separated-values" required></div>
            <div class="form-button"><button class="btn primary">Upload leave balances</button></div>
        </form>
        <p class="muted small leave-format-note">Required columns: EMP ID, Jan–Dec, Grand Total and Total Entitlement. The uploader also reads Name, Status, Process, DOJ and Tenure when provided. EMP ID must match an agent account; an OIT prefix is allowed.</p>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><h2><?= (int)$year ?> leave balances</h2><p class="muted">Used is the sum of the monthly columns. Pending cannot go below zero; overuse is shown separately.</p></div>
            <form method="get" class="leave-year-filter"><label for="viewYear">Year</label><input id="viewYear" type="number" name="year" min="2000" max="2100" value="<?= (int)$year ?>"><button class="btn secondary">View</button></form>
        </div>
        <div class="table-wrap">
            <table class="leave-table">
                <thead><tr><th>EMP ID</th><th>Name</th><th>Status</th><th>Process</th><th>DOJ</th><th>Tenure</th><?php foreach (array_keys($months) as $month): ?><th><?= e(substr($month, 0, 3)) ?></th><?php endforeach; ?><th>Total Used</th><th>Total Entitlement</th><th>Pending</th><th>Overused</th></tr></thead>
                <tbody>
                <?php foreach ($agents as $agent): ?>
                    <?php
                    $used = 0.0;
                    foreach (array_keys($months) as $month) {
                        $used += (float)($agent[strtolower($month)] ?? 0);
                    }
                    $entitlement = (float)($agent['total_entitlement'] ?? 0);
                    $hasBalance = $agent['total_entitlement'] !== null;
                    ?>
                    <tr>
                        <td><strong><?= e($agent['agent_id']) ?></strong></td>
                        <td><?= e($agent['name']) ?></td>
                        <td><?= e($agent['employee_status'] ?? '-') ?></td>
                        <td><?= e($agent['process_name'] ?? '-') ?></td>
                        <td><?= $agent['date_of_joining'] ? e(date('d M Y', strtotime($agent['date_of_joining']))) : '-' ?></td>
                        <td><?= $agent['tenure_days'] === null ? '-' : (int)$agent['tenure_days'] . ' days' ?></td>
                        <?php foreach (array_keys($months) as $month): ?><td><?= $hasBalance ? e(rtrim(rtrim(number_format((float)$agent[strtolower($month)], 2, '.', ''), '0'), '.')) : '-' ?></td><?php endforeach; ?>
                        <td><?= $hasBalance ? e(number_format($used, 2, '.', '')) : '-' ?></td>
                        <td><?= $hasBalance ? e(number_format($entitlement, 2, '.', '')) : '-' ?></td>
                        <td><?= $hasBalance ? e(number_format(max(0, $entitlement - $used), 2, '.', '')) : '-' ?></td>
                        <td><?= $hasBalance && $used > $entitlement ? e(number_format($used - $entitlement, 2, '.', '')) : '0' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$agents): ?><tr><td colspan="22" class="center empty-state muted">No agent accounts found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
