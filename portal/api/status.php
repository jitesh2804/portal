<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Please login again.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'agent') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true, 512, JSON_THROW_ON_ERROR);

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        throw new RuntimeException('Invalid CSRF token');
    }

    $action = $data['action'] ?? '';
    $userId = (int)$_SESSION['user_id'];
    $sessionId = (int)$_SESSION['activity_session_id'];
    $pdo = db();
    $timeoutInterval = '(' . (int)AGENT_SESSION_TIMEOUT . " * INTERVAL '1 second')";

    $pdo->beginTransaction();

    $touch = $pdo->prepare("
        UPDATE agent_sessions
           SET last_seen = NOW()
         WHERE id = :id
           AND user_id = :user_id
           AND logout_at IS NULL
           AND login_at > NOW() - {$timeoutInterval}
    RETURNING id
    ");
    $touch->execute([':id' => $sessionId, ':user_id' => $userId]);

    if (!$touch->fetchColumn()) {
        $expire = $pdo->prepare("
            UPDATE agent_sessions
               SET logout_at = login_at + {$timeoutInterval},
                   last_seen = login_at + {$timeoutInterval}
             WHERE id = :id
               AND user_id = :user_id
               AND logout_at IS NULL
               AND login_at <= NOW() - {$timeoutInterval}
            RETURNING logout_at
        ");
        $expire->execute([':id' => $sessionId, ':user_id' => $userId]);
        $expiredAt = $expire->fetchColumn();
        if ($expiredAt !== false) {
            $closeActivity = $pdo->prepare("
                UPDATE activity_log
                   SET end_time = :expired_at
                 WHERE user_id = :user_id
                   AND session_id = :session_id
                   AND end_time IS NULL
            ");
            $closeActivity->execute([
                ':expired_at' => $expiredAt,
                ':user_id' => $userId,
                ':session_id' => $sessionId,
            ]);
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Session ended. Please login again.']);
        exit;
    }

    if ($action === 'idle') {
        startActivity($pdo, $userId, $sessionId, 'IDLE');
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Idle started successfully.']);
        exit;
    }

    if ($action === 'pause') {
        $pauseCodeId = (int)($data['pause_code_id'] ?? 0);
        if ($pauseCodeId <= 0) {
            throw new RuntimeException('Pause code is required.');
        }

        $stmt = $pdo->prepare("
            SELECT id, code_name
              FROM pause_codes
             WHERE id = :id
               AND is_active = TRUE
        ");
        $stmt->execute([':id' => $pauseCodeId]);
        $pause = $stmt->fetch();

        if (!$pause) {
            throw new RuntimeException('Pause code not found or inactive.');
        }

        startActivity($pdo, $userId, $sessionId, 'PAUSE', $pauseCodeId);
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Break started successfully.',
            'pause_code' => $pause['code_name']
        ]);
        exit;
    }

    if ($action === 'heartbeat') {
        $codes = $pdo->query("
            SELECT id, code_name
              FROM pause_codes
             WHERE is_active = TRUE
             ORDER BY code_name
        ")->fetchAll();

        $pdo->commit();
        echo json_encode([
            'success' => true,
            'pause_codes' => $codes
        ]);
        exit;
    }

    throw new RuntimeException('Invalid action.');
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
