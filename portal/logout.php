<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (isLoggedIn() && ($_SESSION['role'] ?? '') === 'agent' && !empty($_SESSION['activity_session_id'])) {
    try {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE agent_sessions
               SET logout_at = NOW(),
                   last_seen = NOW()
             WHERE id = :id
               AND user_id = :user_id
               AND logout_at IS NULL
        ");
        $stmt->execute([
            ':id' => (int)$_SESSION['activity_session_id'],
            ':user_id' => (int)$_SESSION['user_id'],
        ]);

        if ($stmt->rowCount() === 1) {
            closeOpenActivity($pdo, (int)$_SESSION['user_id'], (int)$_SESSION['activity_session_id']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: /login.php');
exit;
