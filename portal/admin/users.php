<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('admin', 'supervisor');

$pdo = db();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = $_POST['action'] ?? '';
        if (!$isAdmin && $action !== 'assign_lob') {
            http_response_code(403);
            exit('Access denied');
        }

        if ($action === 'create') {
            $agentId = trim($_POST['agent_id'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'agent';
            $lob = $_POST['lob'] ?? '';
            if (!in_array($lob, lobOptions(), true)) {
                throw new RuntimeException('Choose Sales, Collection or Backend LOB.');
            }
            if (!in_array($role, ['agent', 'supervisor'], true)) {
                throw new RuntimeException('Choose Agent or Supervisor.');
            }

            if ($agentId === '' || $fullName === '' || strlen($password) < 6) {
                throw new RuntimeException('Agent ID, name and minimum 6 character password are required.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (agent_id, full_name, password_hash, role, lob)
                VALUES (:agent_id, :full_name, :password_hash, :role, :lob)
            ");
            $stmt->execute([
                ':agent_id' => $agentId,
                ':full_name' => $fullName,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':lob' => $lob,
            ]);
            flash('success', ucfirst($role) . ' created successfully.');
        }

        if ($action === 'assign_lob') {
            $lob = $_POST['lob'] ?? '';
            if (!in_array($lob, lobOptions(), true)) {
                throw new RuntimeException('Choose Sales, Collection or Backend LOB.');
            }
            $stmt = $pdo->prepare("UPDATE users SET lob = :lob WHERE id = :id AND role IN ('agent', 'supervisor')");
            $stmt->execute([':lob' => $lob, ':id' => (int)($_POST['id'] ?? 0)]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('User not found.');
            }
            flash('success', 'LOB assigned. The new LOB applies on the next login.');
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("
                UPDATE users
                   SET is_active = NOT is_active
                 WHERE id = :id
                   AND role IN ('agent', 'supervisor')
            ");
            $stmt->execute([':id' => $id]);
            flash('success', 'User status updated.');
        }

        if ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $password = $_POST['new_password'] ?? '';
            if (strlen($password) < 6) {
                throw new RuntimeException('New password must be at least 6 characters.');
            }

            $stmt = $pdo->prepare("
                UPDATE users
                   SET password_hash = :password_hash
                 WHERE id = :id
                   AND role IN ('agent', 'supervisor')
            ");
            $stmt->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $id
            ]);
            flash('success', 'Password reset successfully.');
        }

        redirect('/admin/users.php');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/users.php');
    }
}

$agents = $pdo->query("
    SELECT id, agent_id, full_name, role, lob, is_active, created_at
      FROM users
     WHERE role IN ('agent', 'supervisor')
     ORDER BY agent_id
")->fetchAll();

$flash = pullFlash();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isAdmin ? 'User Management' : 'LOB Assignments' ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/_nav.php'; ?>
<main class="admin-main">
    <div class="page-head">
        <div>
            <span class="eyebrow"><?= $isAdmin ? 'ACCESS CONTROL' : 'TEAM ASSIGNMENTS' ?></span>
            <h1><?= $isAdmin ? 'User Management' : 'LOB Assignments' ?></h1>
            <p class="muted"><?= $isAdmin ? 'Create agents and supervisors, disable access and reset passwords.' : 'Assign Sales, Collection or Backend to agents and supervisors.' ?></p>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($isAdmin): ?>
    <section class="panel">
        <div class="panel-head"><div><h2>Create User</h2><p class="muted">Supervisors can view realtime activity and reports, and assign LOBs.</p></div></div>
        <form method="post" class="form-grid four">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div><label>User ID</label><input name="agent_id" required></div>
            <div><label>Full Name</label><input name="full_name" required></div>
            <div><label>Password</label><input type="password" name="password" minlength="6" required></div>
            <div><label for="userRole">Role</label><select name="role" id="userRole"><option value="agent">Agent</option><option value="supervisor">Supervisor</option></select></div>
            <div><label for="newLob">Assigned LOB</label><select id="newLob" name="lob" required><option value="">Select LOB</option><?php foreach (lobOptions() as $lob): ?><option value="<?= e($lob) ?>"><?= e($lob) ?></option><?php endforeach; ?></select></div>
            <div class="form-button"><button class="btn primary full">Create User</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head"><div><h2>Agents &amp; Supervisors</h2><p class="muted">Assign a LOB before the first login. Changes apply on the next login; existing sessions keep their original LOB.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User ID</th><th>Name</th><th>Assigned LOB</th><th>Role</th><th>Status</th><?php if ($isAdmin): ?><th>Created</th><th>Reset Password</th><th>Action</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($agents as $a): ?>
                    <tr>
                        <td><strong><?= e($a['agent_id']) ?></strong></td>
                        <td><?= e($a['full_name']) ?></td>
                        <td><form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="assign_lob">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <select name="lob" required aria-label="Assigned LOB for <?= e($a['agent_id']) ?>"><option value="">Unassigned</option><?php foreach (lobOptions() as $lob): ?><option value="<?= e($lob) ?>" <?= $a['lob'] === $lob ? 'selected' : '' ?>><?= e($lob) ?></option><?php endforeach; ?></select>
                            <button class="btn secondary">Save LOB</button>
                        </form></td>
                        <td><span class="badge <?= $a['role'] === 'supervisor' ? 'warning' : 'success' ?>"><?= e(ucfirst($a['role'])) ?></span></td>
                        <td><span class="badge <?= $a['is_active'] ? 'success':'danger' ?>"><?= $a['is_active'] ? 'ACTIVE':'DISABLED' ?></span></td>
                        <?php if ($isAdmin): ?>
                        <td><?= e(date('d M Y', strtotime($a['created_at']))) ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <input type="password" name="new_password" placeholder="New password" minlength="6" required>
                                <button class="btn secondary">Reset</button>
                            </form>
                        </td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn <?= $a['is_active'] ? 'danger':'success' ?>">
                                    <?= $a['is_active'] ? 'Disable':'Enable' ?>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
