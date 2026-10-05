<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['code_name'] ?? '');
            if ($name === '') throw new RuntimeException('Pause code name is required.');

            $stmt = $pdo->prepare("
                INSERT INTO pause_codes (code_name)
                VALUES (:name)
            ");
            $stmt->execute([':name' => $name]);
            flash('success', 'Pause code created. It will auto-appear on agent panels.');
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("
                UPDATE pause_codes
                   SET is_active = NOT is_active
                 WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
            flash('success', 'Pause code status updated.');
        }

        redirect('/admin/pause_codes.php');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/pause_codes.php');
    }
}

$codes = $pdo->query("
    SELECT id, code_name, is_active, created_at
      FROM pause_codes
     ORDER BY code_name
")->fetchAll();

$flash = pullFlash();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pause Codes</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/_nav.php'; ?>
<main class="admin-main">
    <div class="page-head">
        <div>
            <span class="eyebrow">DYNAMIC PAUSE MASTER</span>
            <h1>Pause Codes</h1>
            <p class="muted">Create once; active codes appear automatically for agents.</p>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <section class="panel">
        <form method="post" class="form-grid two">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div><label>Pause Code Name</label><input name="code_name" placeholder="Example: Lunch Break" required></div>
            <div class="form-button"><button class="btn primary full">Create Pause Code</button></div>
        </form>
    </section>

    <section class="panel">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Pause Code</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($codes as $c): ?>
                    <tr>
                        <td><strong><?= e($c['code_name']) ?></strong></td>
                        <td><span class="badge <?= $c['is_active'] ? 'success':'danger' ?>"><?= $c['is_active'] ? 'ACTIVE':'INACTIVE' ?></span></td>
                        <td><?= e(date('d M Y H:i', strtotime($c['created_at']))) ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn <?= $c['is_active'] ? 'danger':'success' ?>">
                                    <?= $c['is_active'] ? 'Disable':'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
