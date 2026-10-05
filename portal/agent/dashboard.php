<?php
declare(strict_types=1);

require_once __DIR__ . '/../functions.php';
requireRole('agent');

$activeSession = db()->prepare('SELECT 1 FROM agent_sessions WHERE id = :id AND user_id = :user_id AND logout_at IS NULL');
$activeSession->execute([':id' => $_SESSION['activity_session_id'] ?? 0, ':user_id' => $_SESSION['user_id']]);
if (!$activeSession->fetchColumn()) {
    redirect('/logout.php');
}

$user = currentUser();
$pdo = db();

$current = getCurrentActivity($pdo, $user['id'], $user['session_id']);

$pauseStmt = $pdo->query("
    SELECT id, code_name
      FROM pause_codes
     WHERE is_active = TRUE
     ORDER BY code_name
");
$pauseCodes = $pauseStmt->fetchAll();

$todayStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(end_time, NOW()) - start_time)))
            FILTER (WHERE activity_type = 'IDLE'), 0)::bigint AS idle_seconds,
        COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(end_time, NOW()) - start_time)))
            FILTER (WHERE activity_type = 'PAUSE'), 0)::bigint AS pause_seconds
    FROM activity_log
    WHERE user_id = :user_id
      AND start_time >= CURRENT_DATE
");
$todayStmt->execute([':user_id' => $user['id']]);
$today = $todayStmt->fetch();

$sessionStmt = $pdo->prepare("
    SELECT login_at,
           EXTRACT(EPOCH FROM (NOW() - login_at))::bigint AS login_seconds
      FROM agent_sessions
     WHERE id = :session_id
");
$sessionStmt->execute([':session_id' => $user['session_id']]);
$session = $sessionStmt->fetch();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agent Panel</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="agent-body">
<script src="/assets/ambient.js" defer></script>
<header class="topbar">
    <div class="brand-lockup">
        <span class="brand-mark">AP</span>
        <div><div class="brand-name">Activity Portal</div>
        <div class="brand-sub">Your daily workspace</div></div>
    </div>
    <div class="agent-clocks" aria-label="Current time in New York and India">
        <div class="agent-clock">
            <span>NEW YORK</span>
            <time id="newYorkClock">--:--:--</time>
            <small id="newYorkDate"></small>
        </div>
        <div class="agent-clock">
            <span>INDIA</span>
            <time id="indiaClock">--:--:--</time>
            <small id="indiaDate"></small>
        </div>
    </div>
    <div class="top-actions">
        <div class="user-chip">
            <strong><?= e($user['full_name']) ?></strong>
            <span><?= e($user['agent_id']) ?> &middot; <?= e($user['lob'] ?? 'Unassigned LOB') ?></span>
        </div>
        <a class="btn danger ghost" href="/logout.php">Logout</a>
    </div>
</header>

<main class="page">
    <div class="page-head"><div><span class="eyebrow">MAKE TODAY COUNT</span><h1>Welcome back, <?= e($user['full_name']) ?><span class="title-dot">.</span></h1><p class="muted">A little focus. A well-earned break. A great workday.</p></div><span class="date-chip"><?= e(date('D, d M Y')) ?></span></div>
    <section class="hero-card">
        <div>
            <span class="eyebrow">CURRENT STATUS</span>
            <h1 id="statusText"><?= e($current['activity_type'] ?? 'IDLE') ?></h1>
            <p id="statusSub">
                <?= ($current['activity_type'] ?? 'IDLE') === 'PAUSE'
                    ? e($current['code_name'] ?? 'Break')
                    : 'Ready / Idle' ?>
            </p>
            <div class="session-caption">Session started at <?= e(date('h:i A', strtotime($session['login_at']))) ?> &middot; Let&rsquo;s make it a good one.</div>
        </div>
        <div class="status-orbit"><div class="status-dot <?= ($current['activity_type'] ?? 'IDLE') === 'PAUSE' ? 'pause' : 'idle' ?>" id="statusDot"></div><span>YOUR CURRENT ACTIVITY</span></div>
    </section>

    <section class="cards three">
        <div class="stat-card">
            <span>Login Duration</span>
            <strong id="loginCounter" data-seconds="<?= (int)($session['login_seconds'] ?? 0) ?>">
                <?= e(formatSeconds((int)($session['login_seconds'] ?? 0))) ?>
            </strong>
            <small>Time in this session</small>
        </div>
        <div class="stat-card tone-green">
            <span>Today's Idle</span>
            <strong id="idleCounter" data-seconds="<?= (int)($today['idle_seconds'] ?? 0) ?>">
                <?= e(formatSeconds((int)($today['idle_seconds'] ?? 0))) ?>
            </strong>
            <small>Available time today</small>
        </div>
        <div class="stat-card tone-amber">
            <span>Today's Break</span>
            <strong id="pauseCounter" data-seconds="<?= (int)($today['pause_seconds'] ?? 0) ?>">
                <?= e(formatSeconds((int)($today['pause_seconds'] ?? 0))) ?>
            </strong>
            <small>Recharge time today</small>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>You set the pace.</h2>
                <p class="muted">Manage your availability with a single click.</p>
            </div>
        </div>

        <div class="activity-grid">
            <button id="idleBtn" class="big-action idle-action" type="button">
                <span class="action-icon" aria-hidden="true">&#9654;</span>
                <span>
                    <strong>Go Idle</strong>
                    <small>Resume available/idle time</small>
                </span>
            </button>

            <div class="break-box">
                <label for="pauseCode">Time for a breather?</label>
                <select id="pauseCode">
                    <option value="">Select pause code</option>
                    <?php foreach ($pauseCodes as $code): ?>
                        <option value="<?= (int)$code['id'] ?>"><?= e($code['code_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="pauseBtn" class="btn warning full" type="button">Start Break</button>
            </div>
        </div>

        <div id="message" role="status" aria-live="polite"></div>
    </section>
    <footer class="workspace-footer"><span>ACTIVITY PORTAL <span class="muted">/ Agent workspace</span></span><span class="muted">Your time, clearly organized.</span></footer>
</main>

<script>
const csrf = <?= json_encode(csrfToken()) ?>;

function updateWorldClocks() {
    const now = new Date();
    const clocks = [
        { timeId: 'newYorkClock', dateId: 'newYorkDate', timeZone: 'America/New_York' },
        { timeId: 'indiaClock', dateId: 'indiaDate', timeZone: 'Asia/Kolkata' }
    ];

    for (const clock of clocks) {
        document.getElementById(clock.timeId).textContent = new Intl.DateTimeFormat('en-US', {
            timeZone: clock.timeZone,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        }).format(now);
        document.getElementById(clock.dateId).textContent = new Intl.DateTimeFormat('en-US', {
            timeZone: clock.timeZone,
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        }).format(now);
    }
}

updateWorldClocks();
setInterval(updateWorldClocks, 1000);

function fmt(sec) {
    sec = Math.max(0, parseInt(sec || 0, 10));
    const h = String(Math.floor(sec / 3600)).padStart(2,'0');
    const m = String(Math.floor((sec % 3600) / 60)).padStart(2,'0');
    const s = String(sec % 60).padStart(2,'0');
    return `${h}:${m}:${s}`;
}

function tickCounter(id) {
    const el = document.getElementById(id);
    let value = parseInt(el.dataset.seconds || '0', 10);
    value++;
    el.dataset.seconds = value;
    el.textContent = fmt(value);
}

setInterval(() => {
    tickCounter('loginCounter');
    const status = document.getElementById('statusText').textContent.trim();
    if (status === 'IDLE') tickCounter('idleCounter');
    if (status === 'PAUSE') tickCounter('pauseCounter');
}, 1000);

async function callApi(payload) {
    const res = await fetch('/api/status.php', {
        method: 'POST',
        headers: {
            'Content-Type':'application/json',
            'X-CSRF-Token': csrf
        },
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (res.status === 401 || data.session_expired) {
        window.location.replace('/login.php');
        throw new Error('Session ended. Please login again.');
    }
    if (!res.ok || !data.success) throw new Error(data.message || 'Request failed');
    return data;
}

function setStatus(type, label='') {
    document.getElementById('statusText').textContent = type;
    document.getElementById('statusSub').textContent = type === 'PAUSE' ? label : 'Ready / Idle';
    const dot = document.getElementById('statusDot');
    dot.className = 'status-dot ' + (type === 'PAUSE' ? 'pause' : 'idle');
}

function showMessage(text, ok=true) {
    const alert = document.createElement('div');
    alert.className = 'alert ' + (ok ? 'success' : 'danger');
    alert.textContent = text;
    document.getElementById('message').replaceChildren(alert);
}

document.getElementById('idleBtn').addEventListener('click', async () => {
    try {
        const data = await callApi({action:'idle'});
        setStatus('IDLE');
        showMessage(data.message);
    } catch (e) {
        showMessage(e.message, false);
    }
});

document.getElementById('pauseBtn').addEventListener('click', async () => {
    const codeId = document.getElementById('pauseCode').value;
    if (!codeId) {
        showMessage('Please select a pause code.', false);
        return;
    }
    try {
        const data = await callApi({action:'pause', pause_code_id:codeId});
        setStatus('PAUSE', data.pause_code);
        showMessage(data.message);
    } catch (e) {
        showMessage(e.message, false);
    }
});

async function heartbeat() {
    try {
        const data = await callApi({action:'heartbeat'});
        if (data.pause_codes) {
            const select = document.getElementById('pauseCode');
            const selected = select.value;
            select.replaceChildren(new Option('Select pause code', ''),
                ...data.pause_codes.map(x => new Option(x.code_name, x.id)));
            select.value = selected;
        }
    } catch (e) {
        console.warn(e);
    } finally {
        setTimeout(heartbeat, 2000);
    }
}
heartbeat();
</script>
</body>
</html>
