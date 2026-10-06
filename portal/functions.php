<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header("Location: {$url}");
    exit;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id' => (int)$_SESSION['user_id'],
        'agent_id' => $_SESSION['agent_id'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'lob' => $_SESSION['lob'] ?? null,
        'session_id' => isset($_SESSION['activity_session_id']) ? (int)$_SESSION['activity_session_id'] : null,
    ];
}

function lobOptions(): array
{
    return ['Sales', 'Collection', 'Backend'];
}

function normalizeLobSelection(mixed $selection): array
{
    if (!is_array($selection) || $selection === []) {
        throw new RuntimeException('Choose at least one LOB.');
    }

    foreach ($selection as $lob) {
        if (!is_string($lob) || !in_array($lob, lobOptions(), true)) {
            throw new RuntimeException('Choose Sales, Collection or Backend LOB.');
        }
    }

    return array_values(array_filter(
        lobOptions(),
        static fn(string $lob): bool => in_array($lob, $selection, true)
    ));
}

function parseAssignedLobs(?string $assignedLobs): array
{
    if ($assignedLobs === null || trim($assignedLobs) === '') {
        return [];
    }

    $selection = array_map('trim', explode(',', $assignedLobs));
    if (count(array_unique($selection)) !== count($selection)) {
        throw new RuntimeException('Stored LOB assignment is invalid.');
    }

    return normalizeLobSelection($selection);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
}

function roleHome(string $role): string
{
    return match ($role) {
        'admin' => '/admin/dashboard.php',
        'supervisor' => '/admin/realtime.php',
        'agent' => '/agent/dashboard.php',
        default => '/logout.php',
    };
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Access denied');
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function closeOpenActivity(PDO $pdo, int $userId, int $sessionId): void
{
    $stmt = $pdo->prepare("
        UPDATE activity_log
           SET end_time = NOW()
         WHERE user_id = :user_id
           AND session_id = :session_id
           AND end_time IS NULL
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':session_id' => $sessionId,
    ]);
}

function expireExpiredAgentSessions(PDO $pdo): int
{
    $timeoutSeconds = (int)AGENT_SESSION_TIMEOUT;
    $stmt = $pdo->query("
        WITH expired_sessions AS (
            UPDATE agent_sessions
               SET logout_at = login_at + ({$timeoutSeconds} * INTERVAL '1 second'),
                   last_seen = login_at + ({$timeoutSeconds} * INTERVAL '1 second')
             WHERE logout_at IS NULL
               AND login_at <= NOW() - ({$timeoutSeconds} * INTERVAL '1 second')
            RETURNING id, user_id, logout_at
        ),
        closed_activity AS (
            UPDATE activity_log al
               SET end_time = expired_sessions.logout_at
              FROM expired_sessions
             WHERE al.session_id = expired_sessions.id
               AND al.user_id = expired_sessions.user_id
               AND al.end_time IS NULL
            RETURNING al.id
        )
        SELECT COUNT(*) FROM expired_sessions
    ");

    return (int)$stmt->fetchColumn();
}

function startActivity(PDO $pdo, int $userId, int $sessionId, string $status, ?int $pauseCodeId = null): void
{
    closeOpenActivity($pdo, $userId, $sessionId);

    $stmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, session_id, activity_type, pause_code_id, start_time)
        VALUES (:user_id, :session_id, :activity_type, :pause_code_id, NOW())
    ");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
    $stmt->bindValue(':activity_type', $status);
    if ($pauseCodeId === null) {
        $stmt->bindValue(':pause_code_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':pause_code_id', $pauseCodeId, PDO::PARAM_INT);
    }
    $stmt->execute();
}

function getCurrentActivity(PDO $pdo, int $userId, int $sessionId): ?array
{
    $stmt = $pdo->prepare("
        SELECT al.id,
               al.activity_type,
               al.pause_code_id,
               al.start_time,
               pc.code_name
          FROM activity_log al
          LEFT JOIN pause_codes pc ON pc.id = al.pause_code_id
         WHERE al.user_id = :user_id
           AND al.session_id = :session_id
           AND al.end_time IS NULL
         ORDER BY al.id DESC
         LIMIT 1
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':session_id' => $sessionId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function formatSeconds(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
