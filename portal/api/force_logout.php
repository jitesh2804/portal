<?php
declare(strict_types=1);
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!isLoggedIn() || !in_array($_SESSION['role'] ?? '', ['admin', 'supervisor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Session token expired. Reload the page.']);
    exit;
}
$pdo = null;
try {
    $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $id = filter_var($data['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid session.']);
        exit;
    }
    $pdo = db();
    $pdo->beginTransaction();
    // Same session-row lock used by heartbeat/actions: no activity can reopen after logout.
    $stmt = $pdo->prepare("UPDATE agent_sessions s SET logout_at = NOW(), last_seen = NOW()
        FROM users u WHERE s.id = :id AND u.id = s.user_id AND u.role = 'agent'
        AND s.logout_at IS NULL RETURNING s.user_id");
    $stmt->execute([':id' => $id]);
    $userId = $stmt->fetchColumn();
    if ($userId !== false) {
        closeOpenActivity($pdo, (int)$userId, (int)$id);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $userId !== false ? 'Agent logged out successfully.' : 'Session is already closed or unavailable.']);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not log out this session. Please retry.']);
}
