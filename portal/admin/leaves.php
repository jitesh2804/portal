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

function leaveOptionalNumber(string $value, string $label, int $rowNumber): ?string
{
    $value = trim($value);
    return $value === '' ? null : number_format(leaveNumber($value, $label, $rowNumber), 2, '.', '');
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
    fputcsv($out, ['EMP ID', 'Name', 'Status', 'Process', 'DOJ', 'Tenure', 'Month', 'Total CL', 'Total EL', 'CL Used', 'EL Used', 'CL in Bucket', 'EL in Bucket', 'Total Leaves in Bucket', ...array_keys($months), 'Grand Total']);
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
        $action = $_POST['action'] ?? '';
        if ($action === 'save_allotment') {
            $leaveYear = filter_var($_POST['leave_year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($leaveYear === false || $userId === false) {
                throw new RuntimeException('Choose a valid agent and leave year.');
            }
            $allotment = leaveNumber((string)($_POST['annual_allotment'] ?? ''), 'Annual Allotted Leave', 1);
            $stmt = $pdo->prepare("
                INSERT INTO agent_leave_balances (user_id, leave_year, annual_allotment, uploaded_by)
                SELECT id, :leave_year, :annual_allotment, :uploaded_by
                  FROM users
                 WHERE id = :user_id AND role = 'agent'
                ON CONFLICT (user_id, leave_year) DO UPDATE
                    SET annual_allotment = EXCLUDED.annual_allotment,
                        uploaded_by = EXCLUDED.uploaded_by,
                        uploaded_at = NOW()
            ");
            $stmt->execute([
                ':leave_year' => $leaveYear,
                ':annual_allotment' => number_format($allotment, 2, '.', ''),
                ':uploaded_by' => (int)$_SESSION['user_id'],
                ':user_id' => $userId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Agent not found.');
            }
            flash('success', 'Annual leave allotment saved.');
            redirect('/admin/leaves.php?year=' . $leaveYear);
        }
        if ($action !== 'upload') {
            throw new RuntimeException('Invalid leave upload action.');
        }

        $uploadYear = filter_var($_POST['leave_year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if ($uploadYear === false) {
            throw new RuntimeException('Choose a valid leave year.');
        }
        if (!isset($_FILES['leave_file'])) {
            throw new RuntimeException('Choose a CSV or TSV leave file to upload.');
        }
        $uploadError = (int)$_FILES['leave_file']['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessage = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit. Increase PHP upload_max_filesize and post_max_size, then try again.',
                UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please choose the file and upload again.',
                UPLOAD_ERR_NO_FILE => 'No file was selected. Choose a .csv or .tsv file first.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary folder is missing. Contact your administrator.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file. Contact your administrator.',
                UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload. Contact your administrator.',
                default => 'The file could not be uploaded. Please try again or contact your administrator.',
            };
            throw new RuntimeException($uploadMessage);
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
        $grandTotalIndex = $findHeader(['Grand Total', 'Total Used', 'Used Total']);
        if ($idIndex === null || $grandTotalIndex === null) {
            fclose($handle);
            throw new RuntimeException('The file must include EMP ID and Grand Total headings.');
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
        $dashboardFields = [
            'month_count' => ['Month', 'Months'],
            'total_cl' => ['Total CL'],
            'total_el' => ['Total EL'],
            'cl_used' => ['CL Used'],
            'el_used' => ['EL Used'],
            'cl_in_bucket' => ['CL in Bucket'],
            'el_in_bucket' => ['EL in Bucket'],
            'total_leaves_in_bucket' => ['Total Leaves in Bucket'],
        ];
        $dashboardIndexes = [];
        foreach ($dashboardFields as $column => $aliases) {
            $dashboardIndexes[$column] = $findHeader($aliases);
        }
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

            $dashboardValues = [];
            foreach ($dashboardIndexes as $column => $index) {
                $rawValue = $index === null ? '' : trim((string)($row[$index] ?? ''));
                if ($column === 'month_count') {
                    if ($rawValue !== '' && (!ctype_digit($rawValue) || (int)$rawValue > 32767)) {
                        throw new RuntimeException("Row {$rowNumber}: Month must be a whole number between 0 and 32767.");
                    }
                    $dashboardValues[$column] = $rawValue === '' ? null : (int)$rawValue;
                } else {
                    $label = implode(' ', array_map('ucfirst', explode('_', $column)));
                    $dashboardValues[$column] = leaveOptionalNumber($rawValue, $label, $rowNumber);
                }
            }

            $rows[] = [
                'user_id' => $portalUserId,
                'leave_year' => $uploadYear,
                'grand_total' => number_format($reportedTotal, 2, '.', ''),
                'months' => $monthValues,
                'source_name' => $optionalText($nameIndex),
                'employee_status' => $optionalText($statusIndex),
                'process_name' => $optionalText($processIndex),
                'date_of_joining' => leaveDateOfJoining($optionalText($dojIndex) ?? '', $rowNumber),
                'tenure_days' => $tenure === null ? null : (int)$tenure,
                'dashboard' => $dashboardValues,
            ];
        }
        fclose($handle);
        if ($rows === []) {
            throw new RuntimeException('The leave file has no agent rows to import.');
        }

        $columns = implode(', ', array_map(static fn(string $month): string => strtolower($month), array_keys($months)));
        $monthPlaceholders = implode(', ', array_map(static fn(string $month): string => ':' . strtolower($month), array_keys($months)));
        $updates = ['grand_total = EXCLUDED.grand_total'];
        foreach (array_keys($months) as $month) {
            $column = strtolower($month);
            $updates[] = "{$column} = EXCLUDED.{$column}";
        }
        foreach (['source_name', 'employee_status', 'process_name', 'date_of_joining', 'tenure_days'] as $column) {
            $updates[] = "{$column} = EXCLUDED.{$column}";
        }
        $dashboardColumns = array_keys($dashboardFields);
        foreach ($dashboardColumns as $column) {
            $updates[] = "{$column} = EXCLUDED.{$column}";
        }
        $dashboardPlaceholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, $dashboardColumns));
        $dashboardColumnList = implode(', ', $dashboardColumns);
        $updates[] = 'uploaded_by = EXCLUDED.uploaded_by';
        $updates[] = 'uploaded_at = NOW()';
        $upsert = $pdo->prepare("
            INSERT INTO agent_leave_balances (
                user_id, leave_year, grand_total, {$columns},
                source_name, employee_status, process_name, date_of_joining, tenure_days,
                {$dashboardColumnList}, uploaded_by
            ) VALUES (
                :user_id, :leave_year, :grand_total, {$monthPlaceholders},
                :source_name, :employee_status, :process_name, :date_of_joining, :tenure_days,
                {$dashboardPlaceholders}, :uploaded_by
            )
            ON CONFLICT (user_id, leave_year) DO UPDATE SET " . implode(', ', $updates)
        );

        $pdo->beginTransaction();
        foreach ($rows as $leaveRow) {
            $params = [
                ':user_id' => $leaveRow['user_id'],
                ':leave_year' => $leaveRow['leave_year'],
                ':grand_total' => $leaveRow['grand_total'],
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
            foreach ($leaveRow['dashboard'] as $column => $value) {
                $params[':' . $column] = $value;
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
    SELECT u.id,
           u.agent_id,
           COALESCE(b.source_name, u.full_name) AS name,
           b.employee_status,
           b.process_name,
           b.date_of_joining,
           b.tenure_days,
           b.annual_allotment,
           b.grand_total,
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
            <p class="muted">Upload the monthly leave sheet, then maintain each agent's annual leave allotment separately.</p>
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
        <p class="muted small leave-format-note">Choose a .csv or .tsv file (Excel .xlsx is not supported). Required columns: EMP ID, Jan–Dec and Grand Total. Name, Status, Process, DOJ, Tenure, Month, Total CL/EL, CL/EL Used and Bucket columns are optional. EMP ID must match an agent account; an OIT prefix is allowed. Maximum file size is 5 MB and the server's PHP upload limit must also allow the file.</p>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><h2><?= (int)$year ?> leave balances</h2><p class="muted">Grand Total is imported from the sheet. Set annual allotted leave separately to calculate pending leave.</p></div>
            <form method="get" class="leave-year-filter"><label for="viewYear">Year</label><input id="viewYear" type="number" name="year" min="2000" max="2100" value="<?= (int)$year ?>"><button class="btn secondary">View</button></form>
        </div>
        <div class="table-wrap">
            <table class="leave-table">
                <thead><tr><th>EMP ID</th><th>Name</th><th>Status</th><th>Process</th><th>DOJ</th><th>Tenure</th><?php foreach (array_keys($months) as $month): ?><th><?= e(substr($month, 0, 3)) ?></th><?php endforeach; ?><th>Grand Total</th><th>Annual Allotted</th><th>Pending</th></tr></thead>
                <tbody>
                <?php foreach ($agents as $agent): ?>
                    <?php
                    $allotment = (float)($agent['annual_allotment'] ?? 0);
                    $hasAllotment = $agent['annual_allotment'] !== null;
                    $hasLeaveRecord = $agent['grand_total'] !== null;
                    $pending = $hasAllotment && $hasLeaveRecord
                        ? max(0, $allotment - (float)$agent['grand_total'])
                        : null;
                    ?>
                    <tr>
                        <td><strong><?= e($agent['agent_id']) ?></strong></td>
                        <td><?= e($agent['name']) ?></td>
                        <td><?= e($agent['employee_status'] ?? '-') ?></td>
                        <td><?= e($agent['process_name'] ?? '-') ?></td>
                        <td><?= $agent['date_of_joining'] ? e(date('d M Y', strtotime($agent['date_of_joining']))) : '-' ?></td>
                        <td><?= $agent['tenure_days'] === null ? '-' : (int)$agent['tenure_days'] . ' days' ?></td>
                        <?php foreach (array_keys($months) as $month): ?><td><?= $hasLeaveRecord ? e(rtrim(rtrim(number_format((float)$agent[strtolower($month)], 2, '.', ''), '0'), '.')) : '-' ?></td><?php endforeach; ?>
                        <td><?= $hasLeaveRecord ? e(number_format((float)$agent['grand_total'], 2, '.', '')) : '-' ?></td>
                        <td><form method="post" class="inline-form leave-allotment-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="save_allotment">
                            <input type="hidden" name="user_id" value="<?= (int)$agent['id'] ?>">
                            <input type="hidden" name="leave_year" value="<?= (int)$year ?>">
                            <input type="number" name="annual_allotment" min="0" max="999999.99" step="0.01" value="<?= $hasAllotment ? e(number_format($allotment, 2, '.', '')) : '' ?>" placeholder="Enter days" required>
                            <button class="btn secondary">Save</button>
                        </form></td>
                        <td><?= $pending === null ? 'Set allotment' : e(number_format($pending, 2, '.', '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$agents): ?><tr><td colspan="21" class="center empty-state muted">No agent accounts found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
