<?php
include 'auth_check.php';
include 'db.php';

// Fetch dashboard statistics
$totalDomains = $crawledDomains = $totalUrlsCrawled = $totalEmailsFound = $emailsPendingMautic = 0;
$avgEmailsPerUrl = $avgEmailsPerDomain = 0;
$totalRejected = $blacklistedDomains = 0;

if ($conn) {
    $res = $conn->query("SELECT COUNT(*) AS c FROM domains"); if ($res) $totalDomains = (int)$res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) AS c FROM domains WHERE crawled = 1"); if ($res) $crawledDomains = (int)$res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COALESCE(SUM(urls_crawled),0) AS c FROM domains WHERE crawled = 1"); if ($res) $totalUrlsCrawled = (int)$res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) AS c FROM emails"); if ($res) $totalEmailsFound = (int)$res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) AS c FROM emails WHERE ma IS NULL OR ma = 0"); if ($res) $emailsPendingMautic = (int)$res->fetch_assoc()['c'];

    // Phase 2 metrics (may not exist yet)
    $res = @$conn->query("SELECT COUNT(*) AS c FROM email_rejections"); if ($res) $totalRejected = (int)$res->fetch_assoc()['c'];
    $res = @$conn->query("SELECT COUNT(*) AS c FROM domains WHERE blacklisted = 1"); if ($res) $blacklistedDomains = (int)$res->fetch_assoc()['c'];

    if ($totalUrlsCrawled > 0) $avgEmailsPerUrl = $totalEmailsFound / $totalUrlsCrawled;
    if ($crawledDomains > 0) $avgEmailsPerDomain = $totalEmailsFound / $crawledDomains;
}

$acceptRate = ($totalEmailsFound + $totalRejected) > 0 ? round($totalEmailsFound / ($totalEmailsFound + $totalRejected) * 100) : 100;

// Active campaign summary
$activeCampaign = null;
$activeKeywords = [];
$activeLocations = [];
$activeKwGroupNames = [];
$activeLocGroupNames = [];

$res = @$conn->query("SELECT id, name FROM campaigns WHERE status = 1 LIMIT 1");
if ($res) $activeCampaign = $res->fetch_assoc();

if ($activeCampaign) {
    $cid = (int)$activeCampaign['id'];

    $res = @$conn->query("SELECT kg.name FROM keyword_groups kg INNER JOIN campaign_keyword_groups ckg ON kg.id = ckg.group_id WHERE ckg.campaign_id = {$cid} ORDER BY kg.name");
    if ($res) { while ($r = $res->fetch_assoc()) $activeKwGroupNames[] = $r['name']; }

    if (!empty($activeKwGroupNames)) {
        $res = $conn->query("SELECT DISTINCT k.keyword FROM keywords k INNER JOIN keyword_group_items kgi ON k.id = kgi.keyword_id INNER JOIN campaign_keyword_groups ckg ON kgi.group_id = ckg.group_id WHERE k.status = 1 AND ckg.campaign_id = {$cid} ORDER BY k.keyword");
        if ($res) { while ($r = $res->fetch_assoc()) $activeKeywords[] = $r['keyword']; }
    }

    $res = @$conn->query("SELECT lg.name FROM location_groups lg INNER JOIN campaign_location_groups clg ON lg.id = clg.group_id WHERE clg.campaign_id = {$cid} ORDER BY lg.name");
    if ($res) { while ($r = $res->fetch_assoc()) $activeLocGroupNames[] = $r['name']; }

    if (!empty($activeLocGroupNames)) {
        $res = $conn->query("SELECT DISTINCT l.name FROM locations l INNER JOIN location_group_items lgi ON l.id = lgi.location_id INNER JOIN campaign_location_groups clg ON lgi.group_id = clg.group_id WHERE l.status = 1 AND clg.campaign_id = {$cid} ORDER BY l.name");
        if ($res) { while ($r = $res->fetch_assoc()) $activeLocations[] = $r['name']; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Dashboard</h1>
        <p>Overview of your crawling system and email extraction pipeline.</p>
    </div>

    <?php if (isset($_GET['started'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <strong><?= ucfirst(str_replace('_', ' ', htmlspecialchars($_GET['started']))) ?></strong> started in the background.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-globe"></i></div>
                <div class="stat-value"><?= number_format($totalDomains) ?></div>
                <div class="stat-label">Total Domains</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?= number_format($crawledDomains) ?></div>
                <div class="stat-label">Crawled</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-value"><?= number_format($totalUrlsCrawled) ?></div>
                <div class="stat-label">Pages Crawled</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                <div class="stat-value"><?= number_format($totalEmailsFound) ?></div>
                <div class="stat-label">Emails Found</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-value"><?= number_format($totalRejected) ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                <div class="stat-value"><?= $acceptRate ?>%</div>
                <div class="stat-label">Accept Rate</div>
            </div>
        </div>
    </div>

    <!-- Secondary stats row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value" style="font-size:1.5rem;"><?= sprintf("%.1f", $avgEmailsPerDomain) ?></div>
                <div class="stat-label">Avg Emails / Domain</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value" style="font-size:1.5rem;"><?= sprintf("%.2f", $avgEmailsPerUrl) ?></div>
                <div class="stat-label">Avg Emails / Page</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value" style="font-size:1.5rem;"><?= number_format($emailsPendingMautic) ?></div>
                <div class="stat-label">Pending Mautic Sync</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value" style="font-size:1.5rem;"><?= number_format($blacklistedDomains) ?></div>
                <div class="stat-label">Blacklisted Domains</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Actions -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="mb-0">Automated Tasks</h3>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="stopAllBtn" onclick="stopAllProcesses()">
                            <i class="fas fa-stop-circle me-1"></i>Stop All
                        </button>
                    </div>
                    <p class="text-muted mb-3">Trigger background processes for data acquisition.</p>
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="run_crawler.php" class="action-btn btn-primary w-100">
                                <i class="fas fa-spider"></i>
                                Run Crawler
                            </a>
                            <div class="text-muted mt-1" style="font-size:0.7rem; line-height:1.3;">Visits pending domains, scans their pages, and extracts email addresses into the database.</div>
                        </div>
                        <div class="col-6">
                            <a href="run_get_urls.php" class="action-btn btn-info w-100">
                                <i class="fas fa-link"></i>
                                Get URLs
                            </a>
                            <div class="text-muted mt-1" style="font-size:0.7rem; line-height:1.3;">Searches Google/Bing via SerpAPI using your keywords + locations to discover new domains to crawl.</div>
                        </div>
                        <div class="col-6">
                            <a href="run_get_emails.php" class="action-btn btn-warning w-100">
                                <i class="fas fa-envelope-open-text"></i>
                                Get Emails
                            </a>
                            <div class="text-muted mt-1" style="font-size:0.7rem; line-height:1.3;">Same as Run Crawler above. Picks the next pending domain and crawls it for emails.</div>
                        </div>
                        <div class="col-6">
                            <a href="run_send_mautic.php" class="action-btn btn-success w-100">
                                <i class="fas fa-share-square"></i>
                                Send to Mautic
                            </a>
                            <div class="text-muted mt-1" style="font-size:0.7rem; line-height:1.3;">Sends pending emails to Mautic CRM via API. Marks them as synced or failed after each attempt.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Process Status -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="mb-0">Process Status</h3>
                        <a href="live.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-satellite-dish me-1"></i>Live View</a>
                    </div>
                    <p class="text-muted mb-3">Real-time background task monitoring. Auto-refreshes every 3s.</p>
                    <div class="d-flex flex-column gap-2" id="process-status-list">
                        <?php
                        $procs = [
                            ['key' => 'crawler', 'name' => 'Crawler', 'icon' => 'fa-spider', 'color' => '#2563eb'],
                            ['key' => 'geturls', 'name' => 'Get URLs', 'icon' => 'fa-link', 'color' => '#0891b2'],
                            ['key' => 'getemails', 'name' => 'Get Emails', 'icon' => 'fa-envelope-open-text', 'color' => '#d97706'],
                            ['key' => 'addtomautic', 'name' => 'Mautic Sync', 'icon' => 'fa-share-square', 'color' => '#16a34a'],
                        ];
                        foreach ($procs as $p): ?>
                        <div class="proc-card" id="proc-<?= $p['key'] ?>">
                            <div class="proc-indicator" id="ind-<?= $p['key'] ?>">
                                <div class="proc-spinner" id="spin-<?= $p['key'] ?>"></div>
                                <i class="fas <?= $p['icon'] ?> proc-icon"></i>
                            </div>
                            <div class="proc-info">
                                <div class="proc-name"><?= $p['name'] ?></div>
                                <div class="proc-status" id="status-<?= $p['key'] ?>">Checking...</div>
                            </div>
                            <div class="proc-timer" id="timer-<?= $p['key'] ?>"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Campaign + Live Terminal -->
    <div class="row g-4 mt-0">
        <!-- Active Campaign -->
        <div class="col-lg-6">
            <div class="card h-100" style="border-color:#334155; background:#0f172a;">
                <div class="card-body">
                    <h4 class="mb-3" style="color:#e2e8f0;"><i class="fas fa-bullseye me-2" style="color:#22c55e;"></i>Active Campaign</h4>
                    <?php if ($activeCampaign): ?>
                    <div class="mb-3">
                        <h6 style="color:#94a3b8;" class="mb-2">Campaign</h6>
                        <span class="badge fs-6" style="background:#166534; color:#fff;"><?= htmlspecialchars($activeCampaign['name']) ?></span>
                    </div>
                    <div class="mb-3">
                        <h6 style="color:#94a3b8;" class="mb-2">Keywords <span style="color:#64748b;">(<?= count($activeKeywords) ?>)</span></h6>
                        <?php if (empty($activeKwGroupNames)): ?>
                            <span class="small" style="color:#94a3b8;"><i class="fas fa-info-circle me-1" style="color:#22c55e;"></i>No keyword groups assigned — all active keywords will be used</span>
                        <?php else: ?>
                            <div class="mb-1"><?php foreach ($activeKwGroupNames as $gn): ?><span class="badge me-1 mb-1" style="background:#166534; color:#fff;"><?= htmlspecialchars($gn) ?></span><?php endforeach; ?></div>
                            <div class="d-flex flex-wrap gap-1"><?php foreach ($activeKeywords as $kw): ?><span class="badge" style="background:#1e293b; border:1px solid #475569; color:#e2e8f0;"><?= htmlspecialchars($kw) ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h6 style="color:#94a3b8;" class="mb-2">Locations <span style="color:#64748b;">(<?= count($activeLocations) ?>)</span></h6>
                        <?php if (empty($activeLocGroupNames)): ?>
                            <span class="small" style="color:#94a3b8;"><i class="fas fa-info-circle me-1" style="color:#22c55e;"></i>No location groups assigned — all active locations will be used</span>
                        <?php else: ?>
                            <div class="mb-1"><?php foreach ($activeLocGroupNames as $gn): ?><span class="badge me-1 mb-1" style="background:#166534; color:#fff;"><?= htmlspecialchars($gn) ?></span><?php endforeach; ?></div>
                            <div class="d-flex flex-wrap gap-1"><?php foreach ($activeLocations as $loc): ?><span class="badge" style="background:#1e293b; border:1px solid #475569; color:#e2e8f0;"><?= htmlspecialchars($loc) ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="color:#94a3b8;"><i class="fas fa-exclamation-triangle me-2" style="color:#22c55e;"></i>No active campaign. <a href="campaigns.php" style="color:#4ade80;">Activate one here.</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Live Terminal -->
        <div class="col-lg-6">
            <div class="card h-100" style="border-color:#334155; background:#0b1220;">
                <div class="card-body d-flex flex-column p-0">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2" style="background:rgba(37,99,235,0.1); border-bottom:1px solid rgba(37,99,235,0.15);">
                        <div class="d-flex align-items-center gap-2">
                            <span class="live-pulse" id="terminal-pulse"></span>
                            <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:rgba(255,255,255,0.5);">Live Terminal</span>
                        </div>
                        <a href="live.php" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.7rem;"><i class="fas fa-expand me-1"></i>Full View</a>
                    </div>
                    <div id="terminal-output" style="flex:1; min-height:280px; max-height:400px; overflow-y:auto; padding:12px 14px; font-family:ui-monospace,'Menlo','Consolas',monospace; font-size:0.8rem; color:#d9f99d; line-height:1.6;">
                        <div id="terminal-idle" style="color:#475569; text-align:center; padding-top:60px;">
                            <i class="fas fa-terminal" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                            <div>Waiting for activity...</div>
                            <div style="font-size:0.7rem; margin-top:4px;">Start a task above to see live output here</div>
                        </div>
                        <div id="terminal-lines"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.live-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #16a34a;
    animation: pulse 1.5s ease-in-out infinite;
    flex-shrink: 0;
}
</style>

<style>
.proc-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 0.85rem 1.1rem;
    border-radius: var(--radius-sm);
    border: 1.5px solid var(--border);
    transition: all 0.3s ease;
    background: var(--bg-card);
}
.proc-card.is-running {
    border-color: var(--success);
    background: linear-gradient(135deg, rgba(22,163,74,0.04), rgba(22,163,74,0.01));
    box-shadow: 0 0 16px rgba(22,163,74,0.1);
}
.proc-indicator {
    position: relative;
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--bg-body);
    flex-shrink: 0;
}
.proc-card.is-running .proc-indicator {
    background: rgba(22,163,74,0.1);
}
.proc-icon {
    font-size: 1rem;
    color: var(--text-muted);
    z-index: 1;
    transition: color 0.3s;
}
.proc-card.is-running .proc-icon {
    color: var(--success);
}
.proc-spinner {
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    border: 2.5px solid transparent;
    border-top-color: var(--success);
    border-right-color: var(--success);
    opacity: 0;
    transition: opacity 0.3s;
}
.proc-card.is-running .proc-spinner {
    opacity: 1;
    animation: proc-spin 1.2s linear infinite;
}
@keyframes proc-spin {
    to { transform: rotate(360deg); }
}
.proc-info {
    flex: 1;
    min-width: 0;
}
.proc-name {
    font-weight: 700;
    font-size: 0.95rem;
    line-height: 1.2;
}
.proc-status {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 1px;
}
.proc-card.is-running .proc-status {
    color: var(--success);
    font-weight: 600;
}
.proc-timer {
    font-family: ui-monospace, 'Menlo', 'Consolas', monospace;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-muted);
    letter-spacing: -0.5px;
    min-width: 65px;
    text-align: right;
}
.proc-card.is-running .proc-timer {
    color: var(--success);
}
</style>
<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var _procTimers = {};
var _procIntervals = {};

function fmtTimer(s) {
    if (!s && s !== 0) return '';
    s = Math.max(0, Math.floor(s));
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
    if (h > 0) return h + ':' + pad(m) + ':' + pad(sec);
    return pad(m) + ':' + pad(sec);
}

function setProc(name, task) {
    var card = document.getElementById('proc-' + name);
    var status = document.getElementById('status-' + name);
    var timer = document.getElementById('timer-' + name);
    if (!card || !status || !timer) return;

    // Clear any existing live timer
    if (_procIntervals[name]) { clearInterval(_procIntervals[name]); _procIntervals[name] = null; }

    if (!task) {
        card.classList.remove('is-running');
        status.textContent = 'Unknown';
        timer.textContent = '';
        return;
    }

    if (task.running) {
        card.classList.add('is-running');
        status.textContent = 'Running';
        _procTimers[name] = task.elapsed_seconds || 0;
        timer.textContent = fmtTimer(_procTimers[name]);
        // Start a live 1s counter so the timer ticks between polls
        _procIntervals[name] = setInterval(function() {
            _procTimers[name]++;
            timer.textContent = fmtTimer(_procTimers[name]);
        }, 1000);
    } else if (task.stale_pid) {
        card.classList.remove('is-running');
        status.innerHTML = '<span style="color:var(--warning)">Stale Process</span>';
        timer.textContent = '';
    } else {
        card.classList.remove('is-running');
        status.textContent = 'Idle';
        timer.textContent = '';
    }
}

function showLastRun(data) {
    var lr = data.last_run;
    if (!lr) return;
    var crawlerStatus = document.getElementById('status-crawler');
    var crawlerCard = document.getElementById('proc-crawler');
    // Only update crawler card if it's idle
    if (crawlerCard && !crawlerCard.classList.contains('is-running') && crawlerStatus) {
        var ago = lr.ended_at ? timeAgo(lr.ended_at) : '';
        crawlerStatus.innerHTML = '<span style="color:var(--text-muted)">Last: <strong>' + (lr.domain || '?') + '</strong> — ' +
            lr.emails + ' emails, ' + lr.pages + ' pages' +
            (ago ? ' <span style="opacity:0.6">(' + ago + ')</span>' : '') + '</span>';
    }
}

function timeAgo(dateStr) {
    var d = new Date(dateStr.replace(' ', 'T') + 'Z');
    var now = new Date();
    var diff = Math.floor((now - d) / 1000);
    if (isNaN(diff) || diff < 0) return '';
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

var _terminalLines = [];
var _terminalMaxLines = 100;
var _lastTerminalState = '';

function terminalAppend(line) {
    _terminalLines.push(line);
    if (_terminalLines.length > _terminalMaxLines) _terminalLines.shift();
    renderTerminal();
}

function renderTerminal() {
    var idle = document.getElementById('terminal-idle');
    var lines = document.getElementById('terminal-lines');
    var output = document.getElementById('terminal-output');
    var pulse = document.getElementById('terminal-pulse');
    if (!lines || !output) return;

    if (_terminalLines.length === 0) {
        if (idle) idle.style.display = '';
        lines.innerHTML = '';
        if (pulse) pulse.style.animationPlayState = 'paused';
        return;
    }

    if (idle) idle.style.display = 'none';
    if (pulse) pulse.style.animationPlayState = 'running';
    lines.innerHTML = _terminalLines.join('');
    // Auto-scroll to bottom
    output.scrollTop = output.scrollHeight;
}

function showLiveActivity(live) {
    var hasActivity = false;
    var newState = '';

    // Search activity (Get URLs)
    if (live.search) {
        hasActivity = true;
        var s = live.search;
        var phaseLabel = s.phase ? 'P' + s.phase : '';
        var phaseColor = s.phase === 3 ? '#a78bfa' : (s.phase === 2 ? '#f59e0b' : '#60a5fa');
        newState = 'search:' + (s.engine||'') + ':' + (s.keyword||'') + ':' + (s.location||'') + ':' + (s.inserted_so_far||0) + ':' + (s.phase||'');

        if (newState !== _lastTerminalState) {
            var ts = new Date().toLocaleTimeString();
            var line = '<div><span style="color:#475569;">[' + ts + ']</span> ';
            if (phaseLabel) line += '<span style="color:' + phaseColor + ';font-weight:700;">' + phaseLabel + '</span> ';
            line += '<span style="color:#60a5fa;">' + (s.engine||'') + '</span> ';
            line += '<span style="color:#f59e0b;">' + (s.location||'') + '</span> ';
            line += '<span style="color:#34d399;">' + (s.keyword||'') + '</span>';
            line += ' <span style="color:#64748b;">(' + (s.inserted_so_far||0) + ' domains)</span>';
            if (s.query) line += '<div style="color:#334155;font-size:0.7rem;margin-left:60px;">q: ' + s.query + '</div>';
            line += '</div>';
            terminalAppend(line);
            _lastTerminalState = newState;
        }
    }

    // Crawl activity
    if (live.crawl) {
        hasActivity = true;
        var c = live.crawl;
        newState = 'crawl:' + (c.domain||'') + ':' + (c.pages||0) + ':' + (c.emails||0);

        if (newState !== _lastTerminalState) {
            var ts = new Date().toLocaleTimeString();
            var line = '<div><span style="color:#475569;">[' + ts + ']</span> ';
            line += '<span style="color:#a78bfa;font-weight:700;">CRAWL</span> ';
            line += '<span style="color:#c084fc;">' + (c.domain||'') + '</span>';
            line += ' <span style="color:#64748b;">pages:' + (c.pages||0);
            if (c.budget) line += '/' + c.budget;
            line += ' emails:' + (c.emails||0);
            line += ' rejected:' + (c.rejected||0);
            line += '</span></div>';
            terminalAppend(line);
            _lastTerminalState = newState;
        }
    }

    if (!hasActivity && _lastTerminalState !== '' && _lastTerminalState !== 'idle') {
        var ts = new Date().toLocaleTimeString();
        terminalAppend('<div><span style="color:#475569;">[' + ts + ']</span> <span style="color:#64748b;">All processes idle</span></div>');
        _lastTerminalState = 'idle';
    }
}

function updateStatus() {
    fetch('status_api.php', { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.ok) return;
            var t = data.tasks || {};
            setProc('crawler', t.crawler);
            setProc('geturls', t.geturls);
            setProc('getemails', t.getemails);
            setProc('addtomautic', t.addtomautic);
            showLastRun(data);
            showLiveActivity(data.live || {});
        })
        .catch(function(e) { console.error('Status error:', e); });
}
updateStatus();
setInterval(updateStatus, 3000);

function stopAllProcesses() {
    if (!confirm('Stop ALL running processes? This will kill any active crawlers, URL fetchers, and Mautic sync.')) return;
    var btn = document.getElementById('stopAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Stopping...';
    fetch('stop_all.php', { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-stop-circle me-1"></i>Stop All';
            if (data.ok) {
                alert(data.message);
                updateStatus();
            } else {
                alert('Error: ' + (data.error || 'Unknown'));
            }
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-stop-circle me-1"></i>Stop All';
            alert('Network error');
        });
}
</script>

</body>
</html>
