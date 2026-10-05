<section class="cards three">
    <div class="stat-card"><span>Online sessions</span><strong id="liveOnline">&mdash;</strong><small>Currently connected</small></div>
    <div class="stat-card tone-green"><span>Idle / Available</span><strong id="liveIdle">&mdash;</strong><small>Ready for work</small></div>
    <div class="stat-card tone-amber"><span>On break</span><strong id="livePause">&mdash;</strong><small>Current pause activity</small></div>
</section>
<section class="panel">
    <div class="panel-head"><div><h2>Live user activity</h2><p class="muted">Updates every 2 seconds. Disconnected sessions drop off after 90 seconds without a heartbeat.</p></div><button id="liveRefresh" type="button" class="btn secondary">Refresh now</button></div>
    <div class="table-toolbar"><label class="search-field" for="liveSearch"><input id="liveSearch" type="search" aria-label="Search name or agent ID" placeholder="Search name or agent ID"></label><label for="liveFilter" class="live-filter">Activity<select id="liveFilter"><option value="">All activity</option><option value="IDLE">Idle / Available</option><option value="PAUSE">On break</option></select></label></div>
    <label for="liveLob" class="live-filter">LOB<select id="liveLob"><option value="">All LOBs</option><option>Sales</option><option>Collection</option><option>Backend</option><option value="unassigned">Unassigned</option></select></label>
    <p id="liveConnection" class="muted small" role="status">Connecting to live activity...</p>
    <div id="liveActionMessage" role="status" data-csrf="<?= e(csrfToken()) ?>"></div>
    <div class="table-wrap"><table><thead><tr><th>Agent ID</th><th>Name</th><th>LOB</th><th>Activity</th><th>Pause code</th><th>Login time</th><th>Session duration</th><th>Activity since</th><th>Activity duration</th><th>Last seen</th><th>Action</th></tr></thead><tbody id="liveRows"><tr><td colspan="11" class="center">Loading live activity...</td></tr></tbody></table></div>
</section>
<script src="/assets/live.js" defer></script>
