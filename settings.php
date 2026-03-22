<?php
include 'auth_check.php';
include 'db.php';

$res = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $res ? $res->fetch_assoc() : [];
$min_pages_crawled = isset($settings['min_pages_crawled']) ? (int)$settings['min_pages_crawled'] : 0;
$updated = isset($_GET['updated']) && $_GET['updated'] == 1;

// Phase 2 settings (may not exist yet)
$_p2res = @$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'page_quality_threshold'");
$hasPhase2 = $_p2res && $_p2res->num_rows > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Settings</h1>
        <p>Configure crawler behavior, integrations, and intelligence thresholds.</p>
    </div>

    <?php if ($updated): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>Settings updated successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>Error saving settings. Check server logs.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="post" action="update_settings.php">
        <ul class="nav nav-tabs mb-0" id="settingsTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="crawler-tab" data-bs-toggle="tab" data-bs-target="#crawler" type="button" role="tab">Crawler</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="serpapi-tab" data-bs-toggle="tab" data-bs-target="#serpapi" type="button" role="tab">SerpAPI</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="mautic-tab" data-bs-toggle="tab" data-bs-target="#mautic" type="button" role="tab">Mautic</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab">Notifications</button>
            </li>
            <?php if ($hasPhase2): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="intelligence-tab" data-bs-toggle="tab" data-bs-target="#intelligence" type="button" role="tab">Intelligence</button>
            </li>
            <?php endif; ?>
        </ul>

        <div class="card" style="border-top-left-radius:0;border-top-right-radius:0;">
            <div class="card-body">
                <div class="tab-content" id="settingsTabContent">

                    <!-- Crawler Settings Tab -->
                    <div class="tab-pane fade show active" id="crawler" role="tabpanel">
                        <h3 class="mb-3">Crawler Delays & Limits</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="page_delay_min" class="form-label">Page Delay Min (seconds)</label>
                                <input type="number" class="form-control" id="page_delay_min" name="page_delay_min" value="<?= htmlspecialchars($settings['page_delay_min'] ?? '', ENT_QUOTES) ?>">
                                <div class="form-text">Minimum delay between page requests.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="page_delay_max" class="form-label">Page Delay Max (seconds)</label>
                                <input type="number" class="form-control" id="page_delay_max" name="page_delay_max" value="<?= htmlspecialchars($settings['page_delay_max'] ?? '', ENT_QUOTES) ?>">
                                <div class="form-text">Maximum delay between page requests.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="domain_delay_min" class="form-label">Domain Delay Min (minutes)</label>
                                <input type="number" class="form-control" id="domain_delay_min" name="domain_delay_min" value="<?= htmlspecialchars($settings['domain_delay_min'] ?? '', ENT_QUOTES) ?>">
                                <div class="form-text">Minimum delay before re-crawling same domain.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="domain_delay_max" class="form-label">Domain Delay Max (minutes)</label>
                                <input type="number" class="form-control" id="domain_delay_max" name="domain_delay_max" value="<?= htmlspecialchars($settings['domain_delay_max'] ?? '', ENT_QUOTES) ?>">
                                <div class="form-text">Maximum delay before re-crawling same domain.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="max_depth" class="form-label">Max Crawl Depth</label>
                                <input type="number" class="form-control" id="max_depth" name="max_depth" value="<?= htmlspecialchars($settings['max_depth'] ?? 20) ?>" required>
                                <div class="form-text">Maximum link depth from initial page.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="min_pages_crawled" class="form-label">Min Pages Crawled (per site)</label>
                                <input type="number" min="0" step="1" class="form-control" id="min_pages_crawled" name="min_pages_crawled" value="<?= htmlspecialchars($min_pages_crawled, ENT_QUOTES) ?>">
                                <div class="form-text">Mark site as crawled after this many pages (0 = disabled).</div>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h3 class="mb-3">Search Result Acquisition</h3>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="results_per_location" class="form-label">Results Per Location</label>
                                <input type="number" min="1" class="form-control" id="results_per_location" name="results_per_location" value="<?= htmlspecialchars($settings['results_per_location'] ?? 50, ENT_QUOTES) ?>">
                                <div class="form-text">Target new domains per engine/keyword/location.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="max_pages" class="form-label">Max SERP Pages</label>
                                <input type="number" min="1" class="form-control" id="max_pages" name="max_pages" value="<?= htmlspecialchars($settings['max_pages'] ?? 20, ENT_QUOTES) ?>">
                                <div class="form-text">Max pages to query from SerpAPI.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="over_fetch_factor" class="form-label">Over-fetch Factor</label>
                                <input type="number" min="1" class="form-control" id="over_fetch_factor" name="over_fetch_factor" value="<?= htmlspecialchars($settings['over_fetch_factor'] ?? 5, ENT_QUOTES) ?>">
                                <div class="form-text">Multiplier for SerpAPI results per page.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="out_dir" class="form-label">Output Directory</label>
                                <input type="text" class="form-control" id="out_dir" name="out_dir" value="<?= htmlspecialchars($settings['out_dir'] ?? 'output', ENT_QUOTES) ?>">
                                <div class="form-text">Subdirectory for CSV reports.</div>
                            </div>
                        </div>
                    </div>

                    <!-- SerpAPI Settings Tab -->
                    <div class="tab-pane fade" id="serpapi" role="tabpanel">
                        <h3 class="mb-3">SerpAPI Configuration</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="serp_cooldown_hours" class="form-label">Search Cooldown (hours)</label>
                                <input type="number" class="form-control" id="serp_cooldown_hours" name="serp_cooldown_hours" value="<?= htmlspecialchars($settings['serp_cooldown_hours'] ?? 24) ?>" min="0" required>
                                <div class="form-text">Wait time before re-searching a keyword/engine/location combo.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Mautic Settings Tab -->
                    <div class="tab-pane fade" id="mautic" role="tabpanel">
                        <h3 class="mb-3">Mautic Integration</h3>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="mautic_batch_limit" class="form-label">Batch Limit</label>
                                <input type="number" min="1" class="form-control" id="mautic_batch_limit" name="mautic_batch_limit" value="<?= htmlspecialchars($settings['mautic_batch_limit'] ?? 6, ENT_QUOTES) ?>">
                                <div class="form-text">Emails per sync run.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="mautic_between" class="form-label">Delay Between Requests (us)</label>
                                <input type="number" min="0" class="form-control" id="mautic_between" name="mautic_between" value="<?= htmlspecialchars($settings['mautic_between'] ?? 15000000, ENT_QUOTES) ?>">
                                <div class="form-text">Microseconds between API calls.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="mautic_limit_max" class="form-label">Overall Limit</label>
                                <input type="number" min="0" class="form-control" id="mautic_limit_max" name="mautic_limit_max" value="<?= htmlspecialchars($settings['mautic_limit_max'] ?? 600, ENT_QUOTES) ?>">
                                <div class="form-text">Max emails per execution.</div>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h4 class="mb-3">API Credentials</h4>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="mautic_api_url" class="form-label">Mautic API URL</label>
                                <input type="url" class="form-control" id="mautic_api_url" name="mautic_api_url" value="<?= htmlspecialchars($settings['mautic_api_url'] ?? '', ENT_QUOTES) ?>" placeholder="https://your-mautic.com">
                            </div>
                            <div class="col-md-6">
                                <label for="mautic_api_username" class="form-label">API Username</label>
                                <input type="text" class="form-control" id="mautic_api_username" name="mautic_api_username" value="<?= htmlspecialchars($settings['mautic_api_username'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="mautic_password" class="form-label">API Password</label>
                                <input type="password" class="form-control" id="mautic_password" name="mautic_password" placeholder="Leave blank to keep current">
                            </div>
                        </div>

                        <hr class="my-4">
                        <h4 class="mb-3">Segment & Form</h4>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="mautic_seg_id" class="form-label">Segment ID</label>
                                <input type="number" min="0" class="form-control" id="mautic_seg_id" name="mautic_seg_id" value="<?= htmlspecialchars($settings['mautic_seg_id'] ?? 0, ENT_QUOTES) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="mautic_form_id" class="form-label">Form ID</label>
                                <input type="number" min="0" class="form-control" id="mautic_form_id" name="mautic_form_id" value="<?= htmlspecialchars($settings['mautic_form_id'] ?? 0, ENT_QUOTES) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="mautic_form_name" class="form-label">Form Name</label>
                                <input type="text" class="form-control" id="mautic_form_name" name="mautic_form_name" value="<?= htmlspecialchars($settings['mautic_form_name'] ?? '', ENT_QUOTES) ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="mautic_form_post_url" class="form-label">Form Post URL</label>
                                <input type="url" class="form-control" id="mautic_form_post_url" name="mautic_form_post_url" value="<?= htmlspecialchars($settings['mautic_form_post_url'] ?? '', ENT_QUOTES) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Email Notification Settings Tab -->
                    <div class="tab-pane fade" id="email" role="tabpanel">
                        <h3 class="mb-3">Notification Email Settings</h3>
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="email_from" class="form-label">From Address</label>
                                <input type="email" class="form-control" id="email_from" name="email_from" value="<?= htmlspecialchars($settings['email_from'] ?? '', ENT_QUOTES) ?>" placeholder="from@example.com">
                            </div>
                            <div class="col-md-4">
                                <label for="email_to" class="form-label">To Address</label>
                                <input type="email" class="form-control" id="email_to" name="email_to" value="<?= htmlspecialchars($settings['email_to'] ?? '', ENT_QUOTES) ?>" placeholder="to@example.com">
                            </div>
                            <div class="col-md-4">
                                <label for="email_subj_prefix" class="form-label">Subject Prefix</label>
                                <input type="text" class="form-control" id="email_subj_prefix" name="email_subj_prefix" value="<?= htmlspecialchars($settings['email_subj_prefix'] ?? 'SERP Report', ENT_QUOTES) ?>">
                            </div>
                        </div>

                        <h4 class="mb-3">Per-Script Notifications</h4>
                        <div class="row g-3">
                            <?php
                            $scripts = [
                                ['key' => 'add_website', 'label' => 'Manage Domains', 'icon' => 'fa-globe'],
                                ['key' => 'addtomautic', 'label' => 'Mautic Sync', 'icon' => 'fa-share-square'],
                                ['key' => 'geturls', 'label' => 'Get URLs', 'icon' => 'fa-link'],
                                ['key' => 'crawler', 'label' => 'Crawler', 'icon' => 'fa-spider'],
                            ];
                            foreach ($scripts as $s):
                                $successKey = "enable_email_{$s['key']}_success";
                                $errorKey = "enable_email_{$s['key']}_error";
                            ?>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body py-3">
                                        <h4 class="mb-2"><i class="fas <?= $s['icon'] ?> me-2" style="color:var(--primary);"></i><?= $s['label'] ?></h4>
                                        <div class="form-check form-switch mb-1">
                                            <input class="form-check-input" type="checkbox" id="<?= $successKey ?>" name="<?= $successKey ?>" value="1" <?= (isset($settings[$successKey]) && (int)$settings[$successKey] === 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="<?= $successKey ?>">Success notifications</label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="<?= $errorKey ?>" name="<?= $errorKey ?>" value="1" <?= (isset($settings[$errorKey]) && (int)$settings[$errorKey] === 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="<?= $errorKey ?>">Error notifications</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Intelligence Settings Tab (Phase 2) -->
                    <?php if ($hasPhase2): ?>
                    <div class="tab-pane fade" id="intelligence" role="tabpanel">
                        <h3 class="mb-1">Intelligence Engine</h3>
                        <p class="text-muted mb-4">Configure the scoring engine that evaluates page quality, email confidence, and domain reputation.</p>

                        <!-- Master toggle -->
                        <div class="card mb-4" style="border-left:4px solid var(--primary);">
                            <div class="card-body d-flex align-items-center justify-content-between">
                                <div>
                                    <h4 class="mb-1"><i class="fas fa-brain me-2" style="color:var(--primary);"></i>Domain Quality Scoring</h4>
                                    <p class="text-muted mb-0">When enabled, the crawler scores domains before crawling and sets a dynamic page budget.</p>
                                </div>
                                <div class="form-check form-switch" style="font-size:1.25rem;">
                                    <input class="form-check-input" type="checkbox" id="domain_quality_enabled" name="domain_quality_enabled" value="1" <?= (isset($settings['domain_quality_enabled']) && (int)$settings['domain_quality_enabled'] === 1) ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <div class="row g-4">
                            <!-- Page Quality Threshold -->
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h4 class="mb-1"><i class="fas fa-file-alt me-2" style="color:var(--info);"></i>Page Quality Threshold</h4>
                                        <p class="text-muted mb-3">Minimum page quality score (0-100) to extract emails from a page.</p>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="range" class="form-range flex-grow-1" id="page_quality_threshold" name="page_quality_threshold" min="0" max="100" step="5" value="<?= htmlspecialchars($settings['page_quality_threshold'] ?? 30) ?>" oninput="document.getElementById('pqt_val').textContent=this.value">
                                            <span class="badge bg-primary" style="min-width:48px;font-size:1rem;" id="pqt_val"><?= htmlspecialchars($settings['page_quality_threshold'] ?? 30) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mt-1">
                                            <small class="text-muted">Accept all</small>
                                            <small class="text-muted">Very strict</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Email Confidence Threshold -->
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h4 class="mb-1"><i class="fas fa-envelope-open me-2" style="color:var(--success);"></i>Email Confidence Threshold</h4>
                                        <p class="text-muted mb-3">Minimum confidence score (0-100) to accept an extracted email.</p>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="range" class="form-range flex-grow-1" id="email_confidence_threshold" name="email_confidence_threshold" min="0" max="100" step="5" value="<?= htmlspecialchars($settings['email_confidence_threshold'] ?? 40) ?>" oninput="document.getElementById('ect_val').textContent=this.value">
                                            <span class="badge bg-success" style="min-width:48px;font-size:1rem;" id="ect_val"><?= htmlspecialchars($settings['email_confidence_threshold'] ?? 40) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mt-1">
                                            <small class="text-muted">Accept all</small>
                                            <small class="text-muted">Very strict</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Max Fake Ratio -->
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h4 class="mb-1"><i class="fas fa-skull-crossbones me-2" style="color:var(--danger);"></i>Max Fake Email Ratio</h4>
                                        <p class="text-muted mb-3">Stop crawling a domain if the ratio of fake/rejected emails exceeds this threshold.</p>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="range" class="form-range flex-grow-1" id="max_fake_ratio" name="max_fake_ratio" min="0.10" max="1.00" step="0.05" value="<?= htmlspecialchars($settings['max_fake_ratio'] ?? 0.60) ?>" oninput="document.getElementById('mfr_val').textContent=Math.round(this.value*100)+'%'">
                                            <span class="badge bg-danger" style="min-width:48px;font-size:1rem;" id="mfr_val"><?= round(($settings['max_fake_ratio'] ?? 0.60) * 100) ?>%</span>
                                        </div>
                                        <div class="d-flex justify-content-between mt-1">
                                            <small class="text-muted">Strict (10%)</small>
                                            <small class="text-muted">Permissive (100%)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Max Consecutive Low Pages -->
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h4 class="mb-1"><i class="fas fa-layer-group me-2" style="color:var(--warning);"></i>Max Consecutive Low Pages</h4>
                                        <p class="text-muted mb-3">Stop crawling after this many consecutive pages score below the quality threshold.</p>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="number" class="form-control" id="max_consecutive_low_pages" name="max_consecutive_low_pages" min="1" max="20" value="<?= htmlspecialchars($settings['max_consecutive_low_pages'] ?? 3) ?>" style="max-width:100px;">
                                            <span class="text-muted">consecutive pages</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Budget reference -->
                        <div class="card mt-4" style="background:var(--bg-body);">
                            <div class="card-body">
                                <h4 class="mb-3"><i class="fas fa-info-circle me-2" style="color:var(--info);"></i>Crawl Budget Reference</h4>
                                <p class="text-muted mb-3">Page budgets are automatically assigned based on domain quality score:</p>
                                <div class="row g-2">
                                    <div class="col-3">
                                        <div class="text-center p-2 rounded" style="background:var(--success-bg);">
                                            <div class="fw-700" style="color:var(--success);">High (70+)</div>
                                            <div class="fw-800" style="font-size:1.5rem;">20</div>
                                            <div class="text-muted" style="font-size:0.8rem;">pages</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-center p-2 rounded" style="background:var(--warning-bg);">
                                            <div class="fw-700" style="color:var(--warning);">Medium (45-69)</div>
                                            <div class="fw-800" style="font-size:1.5rem;">5</div>
                                            <div class="text-muted" style="font-size:0.8rem;">pages</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-center p-2 rounded" style="background:var(--danger-bg);">
                                            <div class="fw-700" style="color:var(--danger);">Low (20-44)</div>
                                            <div class="fw-800" style="font-size:1.5rem;">2</div>
                                            <div class="text-muted" style="font-size:0.8rem;">pages</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-center p-2 rounded" style="background:#1e1e1e;">
                                            <div class="fw-700" style="color:#ef4444;">Spam (&lt;20)</div>
                                            <div class="fw-800" style="font-size:1.5rem;color:#ef4444;">0</div>
                                            <div class="text-muted" style="font-size:0.8rem;">skipped</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <div class="mt-4 mb-4">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save me-2"></i>Save All Settings
            </button>
        </div>
    </form>
</div>

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
