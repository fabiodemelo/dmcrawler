<?php
include 'auth_check.php';
include 'db.php';
require_once __DIR__ . '/includes/blacklist.php';

// Check if Phase 2 tables exist
$hasRejections = (bool)@$conn->query("SELECT 1 FROM email_rejections LIMIT 0");
$hasMetrics = (bool)@$conn->query("SELECT 1 FROM crawl_metrics LIMIT 0");
$hasBlacklist = (bool)@$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'blacklisted'");

// Overall stats
$totalEmails = $totalRejected = $acceptRate = 0;
$res = $conn->query("SELECT COUNT(*) AS c FROM emails"); if ($res) $totalEmails = (int)$res->fetch_assoc()['c'];
if ($hasRejections) {
    $res = $conn->query("SELECT COUNT(*) AS c FROM email_rejections"); if ($res) $totalRejected = (int)$res->fetch_assoc()['c'];
}
$acceptRate = ($totalEmails + $totalRejected) > 0 ? round($totalEmails / ($totalEmails + $totalRejected) * 100, 1) : 100;

// Rejection breakdown
$rejectionBreakdown = [];
if ($hasRejections) {
    $res = $conn->query("SELECT rejection_category, COUNT(*) AS c FROM email_rejections GROUP BY rejection_category ORDER BY c DESC");
    while ($res && $row = $res->fetch_assoc()) {
        $rejectionBreakdown[] = $row;
    }
}

// Domain quality distribution
$qualityDist = [];
if ($hasBlacklist) {
    $res = $conn->query("SELECT quality_tier, COUNT(*) AS c FROM domains WHERE quality_tier IS NOT NULL GROUP BY quality_tier ORDER BY FIELD(quality_tier, 'high','medium','low','spam')");
    while ($res && $row = $res->fetch_assoc()) {
        $qualityDist[] = $row;
    }
}

// Recent crawl metrics (last 14 days)
$recentMetrics = [];
if ($hasMetrics) {
    $res = $conn->query("SELECT DATE(run_started_at) AS day, SUM(pages_crawled) AS pages, SUM(valid_emails) AS valid, SUM(rejected_emails) AS rejected, COUNT(*) AS runs FROM crawl_metrics WHERE run_started_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(run_started_at) ORDER BY day DESC");
    while ($res && $row = $res->fetch_assoc()) {
        $recentMetrics[] = $row;
    }
}

// Avg pages per valid email
$avgPagesPerEmail = 0;
if ($hasMetrics) {
    $res = $conn->query("SELECT AVG(pages_per_valid_email) AS avg_ppe FROM crawl_metrics WHERE pages_per_valid_email IS NOT NULL AND run_started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($res && $row = $res->fetch_assoc()) $avgPagesPerEmail = round((float)$row['avg_ppe'], 1);
}

// Blacklisted domains
$blacklisted = [];
$blacklistedCount = 0;
if ($hasBlacklist) {
    $blacklistedCount = count_blacklisted_domains();
    $blacklisted = get_blacklisted_domains(20);
}

// Handle un-blacklist action
if (isset($_POST['unblacklist_id']) && $hasBlacklist) {
    unblacklist_domain((int)$_POST['unblacklist_id']);
    header('Location: metrics.php#blacklist');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metrics — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Metrics & Analytics</h1>
        <p>Intelligence pipeline performance and quality metrics.</p>
    </div>

    <?php if (!$hasRejections || !$hasMetrics): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Phase 2 migration has not been run yet. <a href="migrate_phase2.php">Run migration</a> to enable full metrics.
    </div>
    <?php endif; ?>

    <!-- Top Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                <div class="stat-value"><?= $acceptRate ?>%</div>
                <div class="stat-label">Acceptance Rate</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-value"><?= $avgPagesPerEmail ?: '—' ?></div>
                <div class="stat-label">Pages / Valid Email (7d avg)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-ban"></i></div>
                <div class="stat-value"><?= number_format($totalRejected) ?></div>
                <div class="stat-label">Total Rejected</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-skull-crossbones"></i></div>
                <div class="stat-value"><?= number_format($blacklistedCount) ?></div>
                <div class="stat-label">Blacklisted Domains</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Rejection Breakdown -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h3 class="mb-3">Rejection Breakdown</h3>
                    <?php if (empty($rejectionBreakdown)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-pie"></i>
                            <h4>No rejections yet</h4>
                            <p>Rejection data will appear after the crawler runs with Phase 2 intelligence.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-card">
                            <table class="table">
                                <thead><tr><th>Category</th><th class="text-end">Count</th><th class="text-end">%</th></tr></thead>
                                <tbody>
                                <?php foreach ($rejectionBreakdown as $r):
                                    $pct = $totalRejected > 0 ? round($r['c'] / $totalRejected * 100, 1) : 0;
                                    $label = str_replace('_', ' ', ucfirst($r['rejection_category']));
                                ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($label) ?></span></td>
                                    <td class="text-end"><?= number_format($r['c']) ?></td>
                                    <td class="text-end"><?= $pct ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Domain Quality Distribution -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h3 class="mb-3">Domain Quality Distribution</h3>
                    <?php if (empty($qualityDist)): ?>
                        <div class="empty-state">
                            <i class="fas fa-star-half-alt"></i>
                            <h4>No quality data yet</h4>
                            <p>Domain quality scores appear after crawling with Phase 2.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $tierColors = ['high' => 'success', 'medium' => 'warning', 'low' => 'danger', 'spam' => 'dark'];
                        $totalScored = array_sum(array_column($qualityDist, 'c'));
                        ?>
                        <?php foreach ($qualityDist as $d):
                            $pct = $totalScored > 0 ? round($d['c'] / $totalScored * 100) : 0;
                            $color = $tierColors[$d['quality_tier']] ?? 'secondary';
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-600"><?= ucfirst($d['quality_tier']) ?></span>
                                <span class="text-muted"><?= number_format($d['c']) ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="progress" style="height:10px;border-radius:5px;">
                                <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Crawl Metrics -->
    <div class="card mt-4">
        <div class="card-body">
            <h3 class="mb-3">Daily Crawl Performance (Last 14 Days)</h3>
            <?php if (empty($recentMetrics)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <h4>No crawl data yet</h4>
                    <p>Daily metrics will appear after running the Phase 2 crawler.</p>
                </div>
            <?php else: ?>
                <div class="table-card">
                    <table class="table">
                        <thead>
                            <tr><th>Date</th><th class="text-end">Runs</th><th class="text-end">Pages</th><th class="text-end">Valid Emails</th><th class="text-end">Rejected</th><th class="text-end">Accept Rate</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentMetrics as $m):
                            $dayRate = ($m['valid'] + $m['rejected']) > 0 ? round($m['valid'] / ($m['valid'] + $m['rejected']) * 100) : 100;
                        ?>
                        <tr>
                            <td class="fw-600"><?= $m['day'] ?></td>
                            <td class="text-end"><?= $m['runs'] ?></td>
                            <td class="text-end"><?= number_format($m['pages']) ?></td>
                            <td class="text-end text-success fw-600"><?= number_format($m['valid']) ?></td>
                            <td class="text-end text-danger"><?= number_format($m['rejected']) ?></td>
                            <td class="text-end"><span class="badge bg-<?= $dayRate >= 70 ? 'success' : ($dayRate >= 40 ? 'warning' : 'danger') ?>"><?= $dayRate ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Blacklisted Domains -->
    <div class="card mt-4 mb-4" id="blacklist">
        <div class="card-body">
            <h3 class="mb-3">Blacklisted Domains <span class="badge bg-dark"><?= $blacklistedCount ?></span></h3>
            <?php if (empty($blacklisted)): ?>
                <div class="empty-state">
                    <i class="fas fa-shield-alt"></i>
                    <h4>No blacklisted domains</h4>
                    <p>Domains that produce spam or fake emails will be automatically blacklisted.</p>
                </div>
            <?php else: ?>
                <div class="table-card">
                    <table class="table">
                        <thead><tr><th>Domain</th><th>Reason</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($blacklisted as $b): ?>
                        <tr>
                            <td class="fw-600"><?= htmlspecialchars($b['domain']) ?></td>
                            <td><span class="text-muted"><?= htmlspecialchars($b['blacklist_reason'] ?? '—') ?></span></td>
                            <td><?= $b['date_crawled'] ?? '—' ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="unblacklist_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Remove from blacklist?')">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
