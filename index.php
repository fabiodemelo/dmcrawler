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
                        </div>
                        <div class="col-6">
                            <a href="run_get_urls.php" class="action-btn btn-info w-100">
                                <i class="fas fa-link"></i>
                                Get URLs
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="run_get_emails.php" class="action-btn btn-warning w-100">
                                <i class="fas fa-envelope-open-text"></i>
                                Get Emails
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="run_send_mautic.php" class="action-btn btn-success w-100">
                                <i class="fas fa-share-square"></i>
                                Send to Mautic
                            </a>
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

                    <!-- Live Activity Box -->
                    <div id="live-activity-box" class="mt-3" style="display:none;">
                        <div class="live-activity-card">
                            <div class="live-activity-header">
                                <span class="live-pulse"></span>
                                <span class="live-activity-title" id="live-activity-title">Activity</span>
                            </div>
                            <div class="live-activity-body" id="live-activity-body"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.live-activity-card {
    background: linear-gradient(135deg, #0f172a, #1e293b);
    border-radius: var(--radius-sm);
    border: 1px solid rgba(37,99,235,0.3);
    overflow: hidden;
}
.live-activity-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0.6rem 1rem;
    background: rgba(37,99,235,0.1);
    border-bottom: 1px solid rgba(37,99,235,0.15);
}
.live-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #16a34a;
    animation: pulse 1.5s ease-in-out infinite;
    flex-shrink: 0;
}
.live-activity-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.5);
}
.live-activity-body {
    padding: 0.85rem 1rem;
    font-family: ui-monospace, 'Menlo', 'Consolas', monospace;
    font-size: 0.85rem;
    color: #d9f99d;
    line-height: 1.5;
}
.live-activity-body .la-engine {
    color: #60a5fa;
    font-weight: 700;
}
.live-activity-body .la-location {
    color: #f59e0b;
    font-weight: 700;
}
.live-activity-body .la-keyword {
    color: #34d399;
    font-weight: 700;
}
.live-activity-body .la-domain {
    color: #a78bfa;
    font-weight: 700;
}
.live-activity-body .la-stat {
    color: rgba(255,255,255,0.6);
    font-size: 0.8rem;
    margin-top: 4px;
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

function showLiveActivity(live) {
    var box = document.getElementById('live-activity-box');
    var title = document.getElementById('live-activity-title');
    var body = document.getElementById('live-activity-body');
    if (!box || !body) return;

    var hasActivity = false;
    var html = '';

    // Search activity (Get URLs)
    if (live.search) {
        hasActivity = true;
        var s = live.search;
        var phaseLabel = s.phase ? 'P' + s.phase : '';
        var phaseIcon = s.phase === 3 ? 'fa-project-diagram' : (s.phase === 2 ? 'fa-bullseye' : 'fa-search');
        var phaseColor = s.phase === 3 ? '#a78bfa' : (s.phase === 2 ? '#f59e0b' : '#60a5fa');
        html += '<div style="margin-bottom:8px;">';
        html += '<i class="fas ' + phaseIcon + '" style="color:' + phaseColor + ';margin-right:6px;"></i>';
        if (phaseLabel) html += '<span style="color:' + phaseColor + ';font-weight:700;font-size:0.75rem;margin-right:6px;">' + phaseLabel + '</span>';
        html += 'Searching <span class="la-engine">' + s.engine + '</span>';
        html += ' in <span class="la-location">' + s.location + '</span>';
        html += ' for <span class="la-keyword">' + s.keyword + '</span>';
        if (s.query) html += '<div style="color:rgba(255,255,255,0.35);font-size:0.75rem;margin-top:2px;word-break:break-all;">' + s.query + '</div>';
        html += '<div class="la-stat">' + (s.inserted_so_far || 0) + ' domains found so far</div>';
        html += '</div>';
    }

    // Crawl activity
    if (live.crawl) {
        hasActivity = true;
        var c = live.crawl;
        html += '<div>';
        html += '<i class="fas fa-spider" style="color:#a78bfa;margin-right:6px;"></i>';
        html += 'Crawling <span class="la-domain">' + c.domain + '</span>';
        if (c.pages !== undefined) {
            html += '<div class="la-stat">';
            html += 'Pages: ' + c.pages + (c.budget ? '/' + c.budget : '');
            html += ' &bull; Emails: ' + (c.emails || 0);
            html += ' &bull; Rejected: ' + (c.rejected || 0);
            if (c.quality && c.quality !== '?') html += ' &bull; Quality: ' + c.quality;
            html += '</div>';
        }
        html += '</div>';
    }

    if (hasActivity) {
        title.textContent = 'Live Activity';
        body.innerHTML = html;
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
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
