<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!isLoggedIn() || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please sign in as admin or supervisor.']);
    exit;
}
session_write_close();
try {
    $timeoutSeconds = (int)AGENT_SESSION_TIMEOUT;
    $pdo = db();
    expireExpiredAgentSessions($pdo);
    $stmt = $pdo->prepare("
        SELECT s.id, u.agent_id, u.full_name, s.lob, s.login_at, s.last_seen,
               COALESCE(al.activity_type, 'IDLE') AS activity_type, pc.code_name,
               COALESCE(al.start_time, s.login_at) AS start_time,
               GREATEST(0, EXTRACT(EPOCH FROM (NOW() - s.login_at)))::bigint AS session_seconds,
               GREATEST(0, EXTRACT(EPOCH FROM (NOW() - COALESCE(al.start_time, s.login_at))))::bigint AS activity_seconds
          FROM agent_sessions s
          JOIN users u ON u.id = s.user_id
          LEFT JOIN LATERAL (
              SELECT activity_type, pause_code_id, start_time FROM activity_log
               WHERE session_id = s.id AND end_time IS NULL ORDER BY id DESC LIMIT 1
          ) al ON TRUE
          LEFT JOIN pause_codes pc ON pc.id = al.pause_code_id
         WHERE s.logout_at IS NULL
           AND s.login_at >= NOW() - ({$timeoutSeconds} * INTERVAL '1 second')
           AND u.role = 'agent'
         ORDER BY u.agent_id, s.id
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        foreach (['login_at', 'last_seen', 'start_time'] as $field) {
            $row[$field] = date('d M Y H:i:s', strtotime($row[$field]));
        }
    }
    unset($row);
    echo json_encode(['success' => true, 'rows' => $rows, 'updated_at' => date('H:i:s')]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Live data unavailable. Retrying automatically.']);
}
