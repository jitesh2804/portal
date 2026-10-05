<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// Bootstrap is public only until the first admin exists.
if (db()->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn()) {
    requireRole('admin');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $agentId = trim($_POST['agent_id'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($agentId === '' || $fullName === '' || strlen($password) < 6) {
            throw new RuntimeException('Agent ID, full name and minimum 6 character password are required.');
        }

        $stmt = db()->prepare("
            INSERT INTO users (agent_id, full_name, password_hash, role)
            VALUES (:agent_id, :full_name, :password_hash, 'admin')
        ");
        $stmt->execute([
            ':agent_id' => $agentId,
            ':full_name' => $fullName,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $message = 'Admin created successfully. Delete create_admin.php now.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Admin</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-body">
<div class="login-card">
    <h1>Create First Admin</h1>
    <p class="muted">Use once, then delete this file.</p>
    <?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <label>Admin ID</label>
        <input name="agent_id" required>
        <label>Full Name</label>
        <input name="full_name" required>
        <label>Password</label>
        <input type="password" name="password" required minlength="6">
        <button class="btn primary" type="submit">Create Admin</button>
    </form>
</div>
</body>
</html>
