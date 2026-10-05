(() => {
    const body = document.getElementById('liveRows');
    const search = document.getElementById('liveSearch');
    const filter = document.getElementById('liveFilter');
    const lob = document.getElementById('liveLob');
    const connection = document.getElementById('liveConnection');
    const refresh = document.getElementById('liveRefresh');
    const actionMessage = document.getElementById('liveActionMessage');
    const loggingOut = new Set();
    let rows = [], pending = false, timer, stopped = false;
    const duration = value => {
        const seconds = Math.max(0, Number(value) || 0);
        return [Math.floor(seconds / 3600), Math.floor(seconds % 3600 / 60), seconds % 60]
            .map(part => String(part).padStart(2, '0')).join(':');
    };
    function render() {
        const query = search.value.trim().toLowerCase();
        const scoped = rows.filter(row => !lob.value || (row.lob || 'unassigned') === lob.value);
        const visible = scoped.filter(row => (!filter.value || row.activity_type === filter.value) &&
            (row.agent_id.toLowerCase().includes(query) || row.full_name.toLowerCase().includes(query)));
        const fragment = document.createDocumentFragment();
        visible.forEach(row => {
            const tr = document.createElement('tr');
            [row.agent_id, row.full_name, row.lob || 'Unassigned', row.activity_type, row.code_name || '-', row.login_at,
                duration(row.session_seconds), row.start_time, duration(row.activity_seconds), row.last_seen]
                .forEach((value, index) => {
                    const td = document.createElement('td');
                    if (index === 3) {
                        const badge = document.createElement('span');
                        badge.className = 'badge ' + (value === 'PAUSE' ? 'warning' : 'success');
                        badge.textContent = value;
                        td.append(badge);
                    } else td.textContent = value;
                    tr.append(td);
                });
            const action = document.createElement('td');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn danger';
            button.textContent = loggingOut.has(String(row.id)) ? 'Logging out...' : 'Logout user';
            button.disabled = loggingOut.has(String(row.id));
            button.addEventListener('click', () => logoutUser(row));
            action.append(button); tr.append(action);
            fragment.append(tr);
        });
        if (!visible.length) {
            const tr = document.createElement('tr'), td = document.createElement('td');
            td.colSpan = 11;
            td.className = 'center empty-state muted';
            td.textContent = rows.length ? 'No agents match your filters.' : 'No agents online. New logins will appear automatically.';
            tr.append(td); fragment.append(tr);
        }
        body.replaceChildren(fragment);
        document.getElementById('liveOnline').textContent = scoped.length;
        document.getElementById('liveIdle').textContent = scoped.filter(row => row.activity_type === 'IDLE').length;
        document.getElementById('livePause').textContent = scoped.filter(row => row.activity_type === 'PAUSE').length;
        document.querySelectorAll('[data-live-count="online"]').forEach(el => { el.textContent = rows.length; });
        document.querySelectorAll('[data-live-count="pause"]').forEach(el => {
            el.textContent = rows.filter(row => row.activity_type === 'PAUSE').length;
        });
    }
    async function logoutUser(row) {
        const id = String(row.id);
        if (loggingOut.has(id) || !window.confirm('Log out ' + row.full_name + ' (' + row.agent_id + ')?')) return;
        loggingOut.add(id); render();
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch('/api/force_logout.php', {
                method: 'POST', signal: controller.signal,
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': actionMessage.dataset.csrf},
                body: JSON.stringify({session_id: row.id})
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Logout failed.');
            rows = rows.filter(item => String(item.id) !== id);
            actionMessage.textContent = data.message;
            actionMessage.className = 'alert success';
        } catch (error) {
            actionMessage.textContent = error.name === 'AbortError' ? 'Logout response timed out. Refresh to check the session before retrying.' : error.message;
            actionMessage.className = 'alert danger';
        } finally {
            clearTimeout(timeout); loggingOut.delete(id); render();
        }
    }
    async function poll() {
        if (pending || stopped) return;
        clearTimeout(timer);
        pending = true; refresh.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        const started = Date.now();
        try {
            const response = await fetch('/api/live.php', {cache: 'no-store', signal: controller.signal});
            if (response.status === 403) {
                stopped = true;
                throw new Error('Session ended. Please sign in again.');
            }
            if (!response.ok) throw new Error('Live update failed. Showing previous data; retrying...');
            const data = await response.json();
            if (!data.success || !Array.isArray(data.rows)) throw new Error('Invalid live response. Retrying...');
            rows = data.rows; render();
            connection.textContent = 'Live · Updated ' + data.updated_at + ' · Refreshes every 2 seconds';
            connection.className = 'small session-status';
        } catch (error) {
            connection.textContent = error.name === 'AbortError' ? 'Connection timed out. Showing previous data; retrying...' : error.message;
            connection.className = 'small live-error';
        } finally {
            clearTimeout(timeout); pending = false; refresh.disabled = stopped;
            if (!stopped) timer = setTimeout(poll, Math.max(0, 2000 - (Date.now() - started)));
        }
    }
    search.addEventListener('input', render);
    filter.addEventListener('change', render);
    lob.addEventListener('change', render);
    refresh.addEventListener('click', poll);
    poll();
})();
