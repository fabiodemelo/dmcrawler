<?php
include 'auth_check.php';

// API mode — return JSON for AJAX polling
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');
    include 'db.php';

    // Check if crawler is running
    $running = file_exists(__DIR__ . '/crawler.running');
    $startedAt = null;
    $elapsed = 0;
    if ($running) {
        $raw = trim(@file_get_contents(__DIR__ . '/crawler.running') ?: '');
        $ts = strtotime($raw);
        if ($ts) { $startedAt = $raw; $elapsed = time() - $ts; }
    }

    // Get latest crawl metrics
    $current = null;
    $res = $conn->query("SELECT cm.*, d.domain FROM crawl_metrics cm JOIN domains d ON cm.domain_id = d.id ORDER BY cm.id DESC LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) $current = $row;

    // Get recent crawl_pages activity (last 20)
    $activity = [];
    $res = $conn->query("SELECT cp.url, cp.quality_score, cp.emails_extracted, cp.emails_accepted, cp.emails_rejected, cp.crawled_at, d.domain FROM crawl_pages cp JOIN domains d ON cp.domain_id = d.id ORDER BY cp.id DESC LIMIT 20");
    while ($res && $row = $res->fetch_assoc()) $activity[] = $row;

    // Get recent emails found (last 10)
    $recentEmails = [];
    $res = $conn->query("SELECT e.email, e.confidence_score, e.confidence_tier, d.domain, e.created_at FROM emails e JOIN domains d ON e.domain_id = d.id ORDER BY e.id DESC LIMIT 10");
    while ($res && $row = $res->fetch_assoc()) $recentEmails[] = $row;

    // Get recent rejections (last 10)
    $recentRejections = [];
    $res = @$conn->query("SELECT email, rejection_reason, rejection_category, created_at FROM email_rejections ORDER BY id DESC LIMIT 10");
    while ($res && $row = $res->fetch_assoc()) $recentRejections[] = $row;

    echo json_encode([
        'running' => $running,
        'started_at' => $startedAt,
        'elapsed' => $elapsed,
        'current' => $current,
        'activity' => $activity,
        'recent_emails' => $recentEmails,
        'recent_rejections' => $recentRejections,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Activity — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body.live-page {
            background: #0b1220 !important;
            color: #e2e8f0;
            overflow-x: hidden;
        }
        .live-page h1, .live-page h2, .live-page h3, .live-page h4, .live-page h5,
        .live-page p, .live-page span, .live-page label {
            color: #e2e8f0;
        }
        .live-page .page-header p { color: #94a3b8; }

        /* Canvas container */
        .neural-canvas-wrap {
            position: relative;
            width: 100%;
            height: 40vh;
            min-height: 280px;
            overflow: hidden;
            border-bottom: 1px solid rgba(37,99,235,0.15);
        }
        #neuralCanvas {
            display: block;
            width: 100%;
            height: 100%;
        }
        .canvas-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            background: linear-gradient(180deg, transparent 60%, #0b1220 100%);
        }
        .canvas-status-badge {
            position: absolute;
            top: 20px;
            right: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(15,23,42,0.85);
            border: 1px solid rgba(37,99,235,0.25);
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            backdrop-filter: blur(8px);
            z-index: 5;
        }
        .canvas-status-badge.running { color: #4ade80; border-color: rgba(22,163,74,0.4); }
        .status-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #475569;
        }
        .status-dot.running {
            background: #4ade80;
            animation: pulse-dot 1.5s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(74,222,128,0.5); }
            50% { box-shadow: 0 0 0 6px rgba(74,222,128,0); }
        }

        /* Stats bar */
        .live-stats-bar {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            padding: 20px 24px;
            background: rgba(15,23,42,0.6);
            border-bottom: 1px solid rgba(37,99,235,0.1);
        }
        .stat-card {
            background: rgba(30,41,59,0.7);
            border: 1px solid rgba(37,99,235,0.12);
            border-radius: 10px;
            padding: 14px 16px;
            text-align: center;
            transition: border-color 0.3s;
        }
        .stat-card:hover { border-color: rgba(37,99,235,0.3); }
        .stat-card .stat-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .stat-card .stat-value {
            font-size: 22px;
            font-weight: 800;
            color: #e2e8f0;
            font-variant-numeric: tabular-nums;
            transition: color 0.3s;
        }
        .stat-card .stat-value.domain-name {
            font-size: 13px;
            font-weight: 600;
            word-break: break-all;
            line-height: 1.3;
        }
        .stat-card .stat-value.highlight { color: #4ade80; }
        .stat-card .stat-value.warning { color: #fbbf24; }

        .stat-card.status-card .stat-value {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        /* Activity feed */
        .activity-feed-section {
            padding: 20px 24px 32px;
        }
        .feed-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .feed-header h3 {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .feed-header h3 i { color: #4ade80; font-size: 14px; }
        .activity-feed {
            background: #0f172a;
            border: 1px solid rgba(37,99,235,0.12);
            border-radius: 10px;
            padding: 16px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'JetBrains Mono', 'Fira Code', 'SF Mono', 'Consolas', monospace;
            font-size: 12.5px;
            line-height: 1.7;
            scroll-behavior: smooth;
        }
        .activity-feed::-webkit-scrollbar { width: 6px; }
        .activity-feed::-webkit-scrollbar-track { background: transparent; }
        .activity-feed::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 3px; }

        .feed-line { color: #d9f99d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .feed-line .timestamp { color: #64748b; }
        .feed-line .url { color: #94a3b8; }
        .feed-line.email-found { color: #4ade80; font-weight: 600; }
        .feed-line.email-rejected { color: #fbbf24; }
        .feed-line.crawl-visit { color: #d9f99d; }
        .feed-line.system-msg { color: #64748b; font-style: italic; }

        .feed-empty {
            color: #475569;
            text-align: center;
            padding: 40px 0;
            font-family: var(--font);
            font-size: 14px;
        }
        .feed-empty i { font-size: 28px; margin-bottom: 10px; display: block; color: #334155; }

        /* Responsive */
        @media (max-width: 992px) {
            .live-stats-bar { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 576px) {
            .live-stats-bar { grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 12px; }
            .stat-card { padding: 10px 12px; }
            .stat-card .stat-value { font-size: 18px; }
            .neural-canvas-wrap { height: 30vh; min-height: 200px; }
            .activity-feed { max-height: 220px; font-size: 11px; }
        }

        /* Value change flash */
        @keyframes value-flash {
            0% { transform: scale(1); }
            30% { transform: scale(1.15); color: #4ade80; }
            100% { transform: scale(1); }
        }
        .stat-value.flash { animation: value-flash 0.5s ease-out; }
    </style>
</head>
<body class="live-page">
<?php include 'nav.php'; ?>

<!-- Neural Network Canvas -->
<div class="neural-canvas-wrap">
    <canvas id="neuralCanvas"></canvas>
    <div class="canvas-overlay"></div>
    <div class="canvas-status-badge" id="canvasStatusBadge">
        <div class="status-dot" id="canvasStatusDot"></div>
        <span id="canvasStatusText">Idle</span>
    </div>
</div>

<!-- Live Stats Bar -->
<div class="live-stats-bar">
    <div class="stat-card">
        <div class="stat-label">Current Domain</div>
        <div class="stat-value domain-name" id="statDomain">--</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Pages Crawled</div>
        <div class="stat-value" id="statPages">0</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Emails Found</div>
        <div class="stat-value highlight" id="statEmails">0</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Emails Rejected</div>
        <div class="stat-value warning" id="statRejected">0</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Elapsed Time</div>
        <div class="stat-value" id="statElapsed">00:00</div>
    </div>
    <div class="stat-card status-card">
        <div class="stat-label">Status</div>
        <div class="stat-value" id="statStatus">
            <div class="status-dot"></div>
            <span>Idle</span>
        </div>
    </div>
</div>

<!-- Live Activity Feed -->
<div class="activity-feed-section">
    <div class="feed-header">
        <h3><i class="fas fa-terminal"></i> Live Activity Feed</h3>
        <span style="font-size:12px; color:#64748b;" id="feedUpdateTime">Waiting for data...</span>
    </div>
    <div class="activity-feed" id="activityFeed">
        <div class="feed-empty">
            <i class="fas fa-satellite-dish"></i>
            Waiting for crawler activity...
        </div>
    </div>
</div>

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    // ===================== NEURAL NETWORK CANVAS =====================
    const canvas = document.getElementById('neuralCanvas');
    const ctx = canvas.getContext('2d');
    let W, H;
    let isRunning = false;
    let nodes = [];
    const NODE_COUNT = 70;
    const CONNECTION_DIST = 150;

    // Effects queue
    let flashEffects = [];   // {node, startTime, duration}
    let rippleEffects = [];  // {x, y, startTime, duration, maxRadius}

    function resize() {
        const rect = canvas.parentElement.getBoundingClientRect();
        W = canvas.width = rect.width * window.devicePixelRatio;
        H = canvas.height = rect.height * window.devicePixelRatio;
        canvas.style.width = rect.width + 'px';
        canvas.style.height = rect.height + 'px';
        ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    }

    function createNodes() {
        const rW = canvas.parentElement.getBoundingClientRect().width;
        const rH = canvas.parentElement.getBoundingClientRect().height;
        nodes = [];
        for (let i = 0; i < NODE_COUNT; i++) {
            nodes.push({
                x: Math.random() * rW,
                y: Math.random() * rH,
                vx: (Math.random() - 0.5) * 0.4,
                vy: (Math.random() - 0.5) * 0.4,
                baseRadius: 2 + Math.random() * 3,
                radius: 3,
                phase: Math.random() * Math.PI * 2
            });
        }
    }

    function drawFrame(timestamp) {
        const rW = canvas.parentElement.getBoundingClientRect().width;
        const rH = canvas.parentElement.getBoundingClientRect().height;
        ctx.clearRect(0, 0, rW, rH);

        const now = timestamp / 1000;
        const activeColor = isRunning ? 'rgba(22,163,74,' : 'rgba(37,99,235,';
        const nodeColor = isRunning ? '#16a34a' : '#2563eb';
        const nodeGlow = isRunning ? 'rgba(22,163,74,0.3)' : 'rgba(37,99,235,0.15)';
        const lineAlpha = isRunning ? 0.18 : 0.08;

        // Move nodes
        for (const n of nodes) {
            n.x += n.vx;
            n.y += n.vy;
            if (n.x < 0 || n.x > rW) n.vx *= -1;
            if (n.y < 0 || n.y > rH) n.vy *= -1;
            n.x = Math.max(0, Math.min(rW, n.x));
            n.y = Math.max(0, Math.min(rH, n.y));
            n.radius = n.baseRadius + Math.sin(now * 1.5 + n.phase) * 1;
        }

        // Draw connections
        ctx.lineWidth = 0.8;
        for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
                const dx = nodes[i].x - nodes[j].x;
                const dy = nodes[i].y - nodes[j].y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < CONNECTION_DIST) {
                    const alpha = (1 - dist / CONNECTION_DIST) * lineAlpha;
                    ctx.strokeStyle = activeColor + alpha + ')';
                    ctx.beginPath();
                    ctx.moveTo(nodes[i].x, nodes[i].y);
                    ctx.lineTo(nodes[j].x, nodes[j].y);
                    ctx.stroke();
                }
            }
        }

        // Draw ripple effects
        for (let i = rippleEffects.length - 1; i >= 0; i--) {
            const r = rippleEffects[i];
            const elapsed = now - r.startTime;
            if (elapsed > r.duration) { rippleEffects.splice(i, 1); continue; }
            const progress = elapsed / r.duration;
            const radius = progress * r.maxRadius;
            const alpha = (1 - progress) * 0.35;
            ctx.strokeStyle = `rgba(22,163,74,${alpha})`;
            ctx.lineWidth = 2 * (1 - progress);
            ctx.beginPath();
            ctx.arc(r.x, r.y, radius, 0, Math.PI * 2);
            ctx.stroke();
        }

        // Draw nodes
        for (let i = 0; i < nodes.length; i++) {
            const n = nodes[i];
            let drawRadius = n.radius;
            let drawColor = nodeColor;
            let drawGlow = nodeGlow;

            // Check flash effects for this node
            for (let f = flashEffects.length - 1; f >= 0; f--) {
                const fx = flashEffects[f];
                if (fx.nodeIdx === i) {
                    const elapsed = now - fx.startTime;
                    if (elapsed > fx.duration) { flashEffects.splice(f, 1); continue; }
                    const progress = elapsed / fx.duration;
                    const scale = 1 + Math.sin(progress * Math.PI) * 3;
                    drawRadius = n.baseRadius * scale;
                    drawColor = '#4ade80';
                    drawGlow = `rgba(74,222,128,${0.6 * (1 - progress)})`;
                }
            }

            // Glow
            ctx.beginPath();
            ctx.arc(n.x, n.y, drawRadius + 6, 0, Math.PI * 2);
            ctx.fillStyle = drawGlow;
            ctx.fill();

            // Core
            ctx.beginPath();
            ctx.arc(n.x, n.y, drawRadius, 0, Math.PI * 2);
            ctx.fillStyle = drawColor;
            ctx.fill();
        }

        requestAnimationFrame(drawFrame);
    }

    function triggerFlash() {
        const idx = Math.floor(Math.random() * nodes.length);
        flashEffects.push({ nodeIdx: idx, startTime: performance.now() / 1000, duration: 1.2 });
    }

    function triggerRipple() {
        const n = nodes[Math.floor(Math.random() * nodes.length)];
        rippleEffects.push({ x: n.x, y: n.y, startTime: performance.now() / 1000, duration: 1.8, maxRadius: 80 });
    }

    resize();
    createNodes();
    requestAnimationFrame(drawFrame);
    window.addEventListener('resize', () => { resize(); });

    // ===================== LIVE DATA POLLING =====================
    let prevEmailCount = null;
    let prevPageCount = null;
    let prevActivityIds = new Set();
    let elapsedSeconds = 0;
    let elapsedTimer = null;
    const feedEl = document.getElementById('activityFeed');
    let feedLines = [];
    const MAX_FEED_LINES = 100;

    function formatTime(secs) {
        const m = Math.floor(secs / 60).toString().padStart(2, '0');
        const s = (secs % 60).toString().padStart(2, '0');
        return m + ':' + s;
    }

    function addFeedLine(html, cls) {
        if (feedEl.querySelector('.feed-empty')) feedEl.innerHTML = '';
        const div = document.createElement('div');
        div.className = 'feed-line ' + (cls || '');
        div.innerHTML = html;
        feedEl.appendChild(div);
        feedLines.push(div);
        if (feedLines.length > MAX_FEED_LINES) {
            const old = feedLines.shift();
            if (old.parentNode) old.parentNode.removeChild(old);
        }
        feedEl.scrollTop = feedEl.scrollHeight;
    }

    function flashValue(elId) {
        const el = document.getElementById(elId);
        if (!el) return;
        el.classList.remove('flash');
        void el.offsetWidth;
        el.classList.add('flash');
    }

    function updateUI(data) {
        const badge = document.getElementById('canvasStatusBadge');
        const dot = document.getElementById('canvasStatusDot');
        const statusText = document.getElementById('canvasStatusText');
        const statDomain = document.getElementById('statDomain');
        const statPages = document.getElementById('statPages');
        const statEmails = document.getElementById('statEmails');
        const statRejected = document.getElementById('statRejected');
        const statElapsed = document.getElementById('statElapsed');
        const statStatus = document.getElementById('statStatus');
        const feedTime = document.getElementById('feedUpdateTime');

        isRunning = data.running;

        // Canvas badge
        if (data.running) {
            badge.classList.add('running');
            dot.classList.add('running');
            statusText.textContent = 'Crawling';
        } else {
            badge.classList.remove('running');
            dot.classList.remove('running');
            statusText.textContent = 'Idle';
        }

        // Current domain
        const domain = data.current ? data.current.domain : '--';
        statDomain.textContent = domain;

        // Pages
        const pages = data.current ? parseInt(data.current.pages_crawled || 0) : 0;
        if (prevPageCount !== null && pages > prevPageCount) {
            triggerRipple();
        }
        statPages.textContent = pages.toLocaleString();
        if (prevPageCount !== null && pages !== prevPageCount) flashValue('statPages');
        prevPageCount = pages;

        // Emails
        const emails = data.current ? parseInt(data.current.valid_emails || 0) : 0;
        if (prevEmailCount !== null && emails > prevEmailCount) {
            for (let i = 0; i < (emails - prevEmailCount); i++) {
                setTimeout(() => triggerFlash(), i * 200);
            }
        }
        statEmails.textContent = emails.toLocaleString();
        if (prevEmailCount !== null && emails !== prevEmailCount) flashValue('statEmails');
        prevEmailCount = emails;

        // Rejected
        const rejected = data.current ? parseInt(data.current.rejected_emails || 0) : 0;
        statRejected.textContent = rejected.toLocaleString();

        // Elapsed
        elapsedSeconds = data.elapsed || 0;
        statElapsed.textContent = formatTime(elapsedSeconds);
        if (elapsedTimer) clearInterval(elapsedTimer);
        if (data.running) {
            elapsedTimer = setInterval(() => {
                elapsedSeconds++;
                statElapsed.textContent = formatTime(elapsedSeconds);
            }, 1000);
        }

        // Status card
        statStatus.innerHTML = data.running
            ? '<div class="status-dot running"></div><span style="color:#4ade80">Running</span>'
            : '<div class="status-dot"></div><span style="color:#64748b">Idle</span>';

        // Activity feed — process activity, emails, rejections
        const now = new Date();
        const ts = '[' + now.toTimeString().substring(0, 8) + ']';

        // Add recent page visits
        if (data.activity && data.activity.length) {
            const reversed = [...data.activity].reverse();
            for (const a of reversed) {
                const key = a.url + '|' + a.crawled_at;
                if (!prevActivityIds.has(key)) {
                    prevActivityIds.add(key);
                    const crawlTs = a.crawled_at ? '[' + a.crawled_at.substring(11, 19) + ']' : ts;
                    addFeedLine(
                        `<span class="timestamp">${crawlTs}</span> Visiting: <span class="url">${escHtml(a.url)}</span>` +
                        (parseInt(a.emails_extracted) > 0 ? ` — <strong style="color:#4ade80">${a.emails_extracted} email(s) extracted</strong>` : ''),
                        'crawl-visit'
                    );
                }
            }
        }

        // Add recent emails
        if (data.recent_emails && data.recent_emails.length) {
            for (const e of [...data.recent_emails].reverse()) {
                const key = 'email|' + e.email + '|' + e.created_at;
                if (!prevActivityIds.has(key)) {
                    prevActivityIds.add(key);
                    const eTs = e.created_at ? '[' + e.created_at.substring(11, 19) + ']' : ts;
                    addFeedLine(
                        `<span class="timestamp">${eTs}</span> ✓ Email found: <strong>${escHtml(e.email)}</strong> (${escHtml(e.confidence_tier || '?')}, score: ${e.confidence_score || '?'}) from ${escHtml(e.domain)}`,
                        'email-found'
                    );
                }
            }
        }

        // Add recent rejections
        if (data.recent_rejections && data.recent_rejections.length) {
            for (const r of [...data.recent_rejections].reverse()) {
                const key = 'reject|' + r.email + '|' + r.created_at;
                if (!prevActivityIds.has(key)) {
                    prevActivityIds.add(key);
                    const rTs = r.created_at ? '[' + r.created_at.substring(11, 19) + ']' : ts;
                    addFeedLine(
                        `<span class="timestamp">${rTs}</span> ✗ Rejected: ${escHtml(r.email)} — ${escHtml(r.rejection_reason || r.rejection_category || 'Unknown')}`,
                        'email-rejected'
                    );
                }
            }
        }

        feedTime.textContent = 'Updated ' + now.toLocaleTimeString();
    }

    function escHtml(str) {
        if (!str) return '';
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function poll() {
        fetch('live.php?api=status')
            .then(r => r.json())
            .then(data => updateUI(data))
            .catch(err => {
                document.getElementById('feedUpdateTime').textContent = 'Connection error...';
            });
    }

    // Initial load + poll every 3s
    poll();
    setInterval(poll, 3000);

})();
</script>
</body>
</html>
