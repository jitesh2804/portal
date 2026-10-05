(() => {
    if (document.getElementById('ambientNetwork')) return;
    const canvas = document.createElement('canvas');
    canvas.id = 'ambientNetwork';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.prepend(canvas);
    const ctx = canvas.getContext('2d');
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    let width, height, points = [], frame, previous = 0, paused = reduced.matches;
    function resize() {
        width = innerWidth; height = innerHeight;
        const scale = Math.min(devicePixelRatio || 1, 2);
        canvas.width = width * scale; canvas.height = height * scale;
        ctx.setTransform(scale, 0, 0, scale, 0, 0);
        points = Array.from({length: Math.min(65, Math.max(22, Math.round(width * height / 23000)))}, () => ({
            x: Math.random() * width, y: Math.random() * height,
            vx: (Math.random() - .5) * .2, vy: (Math.random() - .5) * .2,
            radius: 1.5 + Math.random() * 1.6
        }));
        draw();
    }
    function draw(step = 0) {
        ctx.clearRect(0, 0, width, height);
        points.forEach((point, i) => {
            point.x += point.vx * step; point.y += point.vy * step;
            if (point.x < 0 || point.x > width) point.vx *= -1;
            if (point.y < 0 || point.y > height) point.vy *= -1;
            points.slice(i + 1).forEach(other => {
                const distance = Math.hypot(point.x - other.x, point.y - other.y);
                if (distance < 195) {
                    ctx.strokeStyle = `rgba(27, 174, 229, ${.17 * (1 - distance / 195)})`;
                    ctx.beginPath(); ctx.moveTo(point.x, point.y); ctx.lineTo(other.x, other.y); ctx.stroke();
                }
            });
            ctx.fillStyle = i % 4 === 0 ? 'rgba(119, 99, 227, .45)' : 'rgba(0, 194, 234, .48)';
            ctx.beginPath(); ctx.arc(point.x, point.y, point.radius, 0, Math.PI * 2); ctx.fill();
        });
    }
    function animate(now) {
        if (now - previous >= 32) { draw(Math.min((now - previous) / 16.67, 3)); previous = now; }
        frame = requestAnimationFrame(animate);
    }
    function syncMotion() {
        cancelAnimationFrame(frame);
        document.body.classList.toggle('motion-paused', paused);
        if (!paused && !document.hidden) { previous = performance.now(); frame = requestAnimationFrame(animate); }
        const button = document.getElementById('motionToggle');
        if (button) { button.textContent = paused ? 'Resume motion' : 'Pause motion'; button.setAttribute('aria-pressed', String(paused)); }
    }
    if (ctx) {
        resize(); syncMotion();
        addEventListener('resize', resize);
        document.addEventListener('visibilitychange', syncMotion);
        reduced.addEventListener('change', () => { paused = reduced.matches; syncMotion(); });
        document.getElementById('motionToggle')?.addEventListener('click', () => { paused = !paused; syncMotion(); });
    }
    const clock = document.getElementById('workspaceClock');
    if (clock) {
        const update = () => { clock.textContent = new Intl.DateTimeFormat('en-IN', {timeZone: 'Asia/Kolkata', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false}).format(new Date()) + ' IST'; clock.dateTime = new Date().toISOString(); };
        update(); setInterval(update, 1000);
    }
    document.getElementById('passwordToggle')?.addEventListener('click', event => {
        const input = document.getElementById('password');
        const show = input.type === 'password'; input.type = show ? 'text' : 'password';
        event.currentTarget.textContent = show ? 'Hide' : 'Show';
        event.currentTarget.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        event.currentTarget.setAttribute('aria-pressed', String(show));
    });
})();
