<?php $page = basename($_SERVER['PHP_SELF']); ?>
<script src="/assets/ambient.js" defer></script>
<aside class="sidebar">
    <div class="side-brand">
        <span class="brand-mark">AP</span>
        <div>
            <strong>Activity Portal</strong>
            <small><?= ($_SESSION['role'] ?? '') === 'supervisor' ? 'Supervisor Console' : 'Admin Console' ?></small>
        </div>
    </div>

    <div class="nav-caption">WORKSPACE</div>
    <nav aria-label="Main navigation">
        <a class="<?= $page === 'realtime.php' ? 'active' : '' ?>" href="/admin/realtime.php"><span class="nav-icon" aria-hidden="true">&#9673;</span>Realtime Activity</a>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <a class="<?= $page === 'dashboard.php' ? 'active' : '' ?>" href="/admin/dashboard.php"><span class="nav-icon" aria-hidden="true">&#9638;</span>Overview</a>
        <a class="<?= $page === 'users.php' ? 'active' : '' ?>" href="/admin/users.php"><span class="nav-icon" aria-hidden="true">&#9823;</span>User Management</a>
        <a class="<?= $page === 'leaves.php' ? 'active' : '' ?>" href="/admin/leaves.php"><span class="nav-icon" aria-hidden="true">&#9636;</span>Agent Leaves</a>
        <a class="<?= $page === 'pause_codes.php' ? 'active' : '' ?>" href="/admin/pause_codes.php"><span class="nav-icon" aria-hidden="true">&#9208;</span>Pause Codes</a>
        <?php else: ?>
        <a class="<?= $page === 'users.php' ? 'active' : '' ?>" href="/admin/users.php"><span class="nav-icon" aria-hidden="true">&#9823;</span>LOB Assignments</a>
        <?php endif; ?>
        <a class="<?= $page === 'report.php' ? 'active' : '' ?>" href="/admin/report.php"><span class="nav-icon" aria-hidden="true">&#9636;</span>Reports</a>
    </nav>

    <div class="sidebar-bottom">
        <div class="sidebar-note"><span class="eyebrow">A clearer workday</span><p>Your people. Your pulse.<br>All in one place.</p></div>
        <div class="user-mini">
            <strong><?= e($_SESSION['full_name'] ?? '') ?></strong>
            <span><?= e($_SESSION['agent_id'] ?? '') ?></span>
            <?php if (!empty($_SESSION['lob'])): ?><span><?= e($_SESSION['lob']) ?> LOB</span><?php endif; ?>
        </div>
        <a class="btn danger ghost full" href="/logout.php">Logout</a>
    </div>
</aside>
