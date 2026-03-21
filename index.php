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
                    <h3 class="mb-3">Automated Tasks</h3>
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
                    <h3 class="mb-3">Process Status</h3>
                    <p class="text-muted mb-3">Live status of background tasks. Updates every 5 seconds.</p>
                    <div class="d-flex flex-column gap-2" id="process-status-list">
                        <div class="status-card">
                            <span class="status-dot idle" id="dot-crawler"></span>
                            <span class="status-name">Crawler</span>
                            <span class="status-info" id="status-crawler">Loading...</span>
                        </div>
                        <div class="status-card">
                            <span class="status-dot idle" id="dot-geturls"></span>
                            <span class="status-name">Get URLs</span>
                            <span class="status-info" id="status-geturls">Loading...</span>
                        </div>
                        <div class="status-card">
                            <span class="status-dot idle" id="dot-getemails"></span>
                            <span class="status-name">Get Emails</span>
                            <span class="status-info" id="status-getemails">Loading...</span>
                        </div>
                        <div class="status-card">
                            <span class="status-dot idle" id="dot-addtomautic"></span>
                            <span class="status-name">Mautic Sync</span>
                            <span class="status-info" id="status-addtomautic">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function fmtElapsed(s) {
    if (!s && s !== 0) return '';
    s = parseInt(s, 10);
    if (isNaN(s) || s < 0) return '';
    if (s < 60) return s + 's';
    var m = Math.floor(s / 60), ss = s % 60;
    if (m < 60) return m + 'm ' + ss + 's';
    return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
}

function setStatus(name, task) {
    var el = document.getElementById('status-' + name);
    var dot = document.getElementById('dot-' + name);
    if (!el || !dot) return;
    dot.className = 'status-dot idle';

    if (!task) { el.textContent = 'Unknown'; return; }

    if (task.running) {
        var elapsed = fmtElapsed(task.elapsed_seconds);
        el.innerHTML = 'Running' + (elapsed ? ' <strong>' + elapsed + '</strong>' : '');
        dot.className = 'status-dot running';
    } else if (task.stale_pid) {
        el.textContent = 'Stale';
        dot.className = 'status-dot stale';
    } else {
        el.textContent = 'Idle';
    }
}

function updateStatus() {
    fetch('status_api.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.ok) return;
            var t = data.tasks || {};
            setStatus('crawler', t.crawler);
            setStatus('geturls', t.geturls);
            setStatus('getemails', t.getemails);
            setStatus('addtomautic', t.addtomautic);
        })
        .catch(e => console.error('Status error:', e));
}
updateStatus();
setInterval(updateStatus, 5000);
</script>
</body>
</html>
