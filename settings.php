<?php
//version 3.3
include 'db.php';

// Helper to read a single setting field safely (creates it only if missing)
// This function needs to be defined if not already in an included file.
// Assuming it's available from db.php or a similar utility file.
if (!function_exists('get_setting_value')) {
    function get_setting_value($field) {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) return null;
        global $conn;
        // Ensure connection is still good or re-establish
        if (!($conn instanceof mysqli) || !$conn->ping()) {
            // Attempt to reconnect or log failure
            // In a more robust system, this would trigger a reconnection.
            // For this context, we'll just return null on connection failure.
            return null;
        }

        $res = $conn->query("SELECT `{$field}` AS v FROM settings WHERE id = 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return $row['v'];
        }
        return null;
    }
}


$res = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $res ? $res->fetch_assoc() : [];
$min_pages_crawled = isset($settings['min_pages_crawled']) ? (int)$settings['min_pages_crawled'] : 0;
$updated = isset($_GET['updated']) && $_GET['updated'] == 1;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Settings</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
    <h2 class="mt-4 mb-4">Application Settings</h2>

    <?php if ($updated): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Settings updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="post" action="update_settings.php" class="mb-4">
        <ul class="nav nav-tabs" id="settingsTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="crawler-tab" data-bs-toggle="tab" data-bs-target="#crawler" type="button" role="tab" aria-controls="crawler" aria-selected="true">Crawler Settings</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="serpapi-tab" data-bs-toggle="tab" data-bs-target="#serpapi" type="button" role="tab" aria-controls="serpapi" aria-selected="false">SerpAPI Settings</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="mautic-tab" data-bs-toggle="tab" data-bs-target="#mautic" type="button" role="tab" aria-controls="mautic" aria-selected="false">Mautic Settings</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab" aria-controls="email" aria-selected="false">Email Settings</button>
            </li>
        </ul>

        <div class="tab-content" id="settingsTabContent">
            <!-- Crawler Settings Tab -->
            <div class="tab-pane fade show active p-3" id="crawler" role="tabpanel" aria-labelledby="crawler-tab">
                <h5 class="mb-3">Crawler Operational Delays & Limits</h5>
                <div class="mb-3">
                    <label for="page_delay_min" class="form-label">Page Delay (Seconds) Min</label>
                    <input type="number" class="form-control" id="page_delay_min" name="page_delay_min" value="<?= htmlspecialchars($settings['page_delay_min'] ?? '', ENT_QUOTES) ?>">
                    <div class="form-text">Minimum delay between page requests during crawling.</div>
                </div>
                <div class="mb-3">
                    <label for="page_delay_max" class="form-label">Page Delay (Seconds) Max</label>
                    <input type="number" class="form-control" id="page_delay_max" name="page_delay_max" value="<?= htmlspecialchars($settings['page_delay_max'] ?? '', ENT_QUOTES) ?>">
                    <div class="form-text">Maximum delay between page requests during crawling.</div>
                </div>
                <div class="mb-3">
                    <label for="domain_delay_min" class="form-label">Domain Delay (Minutes) Min</label>
                    <input type="number" class="form-control" id="domain_delay_min" name="domain_delay_min" value="<?= htmlspecialchars($settings['domain_delay_min'] ?? '', ENT_QUOTES) ?>">
                    <div class="form-text">Minimum delay before re-crawling the same domain.</div>
                </div>
                <div class="mb-3">
                    <label for="domain_delay_max" class="form-label">Domain Delay (Minutes) Max</label>
                    <input type="number" class="form-control" id="domain_delay_max" name="domain_delay_max" value="<?= htmlspecialchars($settings['domain_delay_max'] ?? '', ENT_QUOTES) ?>">
                    <div class="form-text">Maximum delay before re-crawling the same domain.</div>
                </div>
                <div class="mb-3">
                    <label for="max_depth" class="form-label">Max Crawl Depth</label>
                    <input type="number" class="form-control" id="max_depth" name="max_depth" value="<?= htmlspecialchars($settings['max_depth'] ?? 20) ?>" required>
                    <div class="form-text">Maximum depth the crawler will follow links from the initial page.</div>
                </div>
                <div class="mb-3">
                    <label for="min_pages_crawled" class="form-label">Minimum Pages Crawled (per site)</label>
                    <input type="number" min="0" step="1" class="form-control" id="min_pages_crawled" name="min_pages_crawled" value="<?= htmlspecialchars($min_pages_crawled, ENT_QUOTES) ?>">
                    <div class="form-text">
                        If greater than 0, a site will be marked as crawled as soon as at least this many pages have been visited.
                    </div>
                </div>

                <hr>
                <h5 class="mb-3">Search Result Acquisition Settings</h5>
                <div class="mb-3">
                    <label for="results_per_location" class="form-label">Target Results Per Location</label>
                    <input type="number" min="1" step="1" class="form-control" id="results_per_location" name="results_per_location" value="<?= htmlspecialchars($settings['results_per_location'] ?? 50, ENT_QUOTES) ?>">
                    <div class="form-text">Target number of *new* domains to insert per engine/keyword/location combination.</div>
                </div>
                <div class="mb-3">
                    <label for="max_pages" class="form-label">Max SERP Pages to Query</label>
                    <input type="number" min="1" step="1" class="form-control" id="max_pages" name="max_pages" value="<?= htmlspecialchars($settings['max_pages'] ?? 20, ENT_QUOTES) ?>">
                    <div class="form-text">Safety limit: Maximum number of pages to query from SerpAPI for any given engine/keyword/location.</div>
                </div>
                <div class="mb-3">
                    <label for="over_fetch_factor" class="form-label">SerpAPI Over-fetch Factor</label>
                    <input type="number" min="1" step="1" class="form-control" id="over_fetch_factor" name="over_fetch_factor" value="<?= htmlspecialchars($settings['over_fetch_factor'] ?? 5, ENT_QUOTES) ?>">
                    <div class="form-text">Multiplier for 'num' parameter in SerpAPI to get more results per page, hoping for more unique domains.</div>
                </div>
                <div class="mb-3">
                    <label for="out_dir" class="form-label">Output Directory Name</label>
                    <input type="text" class="form-control" id="out_dir" name="out_dir" value="<?= htmlspecialchars($settings['out_dir'] ?? 'output', ENT_QUOTES) ?>">
                    <div class="form-text">Name of the subdirectory within the project root to save CSV reports (e.g., 'output').</div>
                </div>
            </div>

            <!-- SerpAPI Settings Tab -->
            <div class="tab-pane fade p-3" id="serpapi" role="tabpanel" aria-labelledby="serpapi-tab">
                <h5 class="mb-3">SerpAPI Configuration</h5>
                <div class="mb-3">
                    <label for="serp_cooldown_hours" class="form-label">SERP Search Cooldown (hours)</label>
                    <input type="number" class="form-control" id="serp_cooldown_hours" name="serp_cooldown_hours" value="<?= htmlspecialchars($settings['serp_cooldown_hours'] ?? 24) ?>" min="0" required>
                    <div class="form-text">Time (in hours) to wait before re-searching a specific Keyword-Engine-Location combination.</div>
                </div>
                <!-- Add other SerpAPI related settings here if any, e.g., API keys management (though keys are usually in code or env vars) -->
            </div>

            <!-- Mautic Settings Tab -->
            <div class="tab-pane fade p-3" id="mautic" role="tabpanel" aria-labelledby="mautic-tab">
                <h5 class="mb-3">Mautic Integration Settings</h5>
                <div class="mb-3">
                    <label for="mautic_batch_limit" class="form-label">Mautic Batch Limit (Emails per run)</label>
                    <input type="number" min="1" step="1" class="form-control" id="mautic_batch_limit" name="mautic_batch_limit" value="<?= htmlspecialchars($settings['mautic_batch_limit'] ?? 6, ENT_QUOTES) ?>">
                    <div class="form-text">
                        Maximum number of emails to process in one Mautic synchronization run.
                    </div>
                </div>
                <div class="mb-3">
                    <label for="mautic_between" class="form-label">Mautic Delay Between Requests (microseconds)</label>
                    <input type="number" min="0" step="1" class="form-control" id="mautic_between" name="mautic_between" value="<?= htmlspecialchars($settings['mautic_between'] ?? 15000000, ENT_QUOTES) ?>">
                    <div class="form-text">
                        Delay between individual Mautic API calls, in microseconds (e.g., 15,000,000 for 15 seconds).
                    </div>
                </div>
                <div class="mb-3">
                    <label for="mautic_limit_max" class="form-label">Mautic Overall Limit (Emails)</label>
                    <input type="number" min="0" step="1" class="form-control" id="mautic_limit_max" name="mautic_limit_max" value="<?= htmlspecialchars($settings['mautic_limit_max'] ?? 600, ENT_QUOTES) ?>">
                    <div class="form-text">
                        Maximum total emails to attempt to synchronize to Mautic in a single script execution.
                    </div>
                </div>
                <hr>
                <h6 class="mt-4 mb-3">Mautic API Credentials</h6>
                <div class="mb-3">
                    <label for="mautic_api_url" class="form-label">Mautic API URL</label>
                    <input type="url" class="form-control" id="mautic_api_url" name="mautic_api_url" value="<?= htmlspecialchars($settings['mautic_api_url'] ?? '', ENT_QUOTES) ?>" placeholder="e.g., https://your-mautic.com">
                    <div class="form-text">
                        The base URL for your Mautic instance (e.g., https://your-mautic.com).
                    </div>
                </div>
                <div class="mb-3">
                    <label for="mautic_api_username" class="form-label">Mautic API Username</label>
                    <input type="text" class="form-control" id="mautic_api_username" name="mautic_api_username" value="<?= htmlspecialchars($settings['mautic_api_username'] ?? '', ENT_QUOTES) ?>" placeholder="Your Mautic API username">
                    <div class="form-text">
                        The username for Mautic API basic authentication.
                    </div>
                </div>
                <div class="mb-3">
                    <label for="mautic_password" class="form-label">Mautic API Password</label>
                    <input type="password" class="form-control" id="mautic_password" name="mautic_password" placeholder="Leave blank to keep current password">
                    <div class="form-text">
                        The password for Mautic API basic authentication. Leave blank if you don't want to change it.
                    </div>
                </div>
                <hr>
                <h6 class="mt-4 mb-3">Mautic Segment & Form Settings</h6>
                <div class="mb-3">
                    <label for="mautic_seg_id" class="form-label">Mautic Segment ID</label>
                    <input type="number" min="0" step="1" class="form-control" id="mautic_seg_id" name="mautic_seg_id" value="<?= htmlspecialchars($settings['mautic_seg_id'] ?? 0, ENT_QUOTES) ?>">
                    <div class="form-text">The ID of the Mautic segment to add contacts to when using API mode.</div>
                </div>
                <div class="mb-3">
                    <label for="mautic_form_id" class="form-label">Mautic Form ID</label>
                    <input type="number" min="0" step="1" class="form-control" id="mautic_form_id" name="mautic_form_id" value="<?= htmlspecialchars($settings['mautic_form_id'] ?? 0, ENT_QUOTES) ?>">
                    <div class="form-text">The ID of the Mautic form to submit to when using Form mode.</div>
                </div>
                <div class="mb-3">
                    <label for="mautic_form_name" class="form-label">Mautic Form Name</label>
                    <input type="text" class="form-control" id="mautic_form_name" name="mautic_form_name" value="<?= htmlspecialchars($settings['mautic_form_name'] ?? '', ENT_QUOTES) ?>" placeholder="Form alias/name as in Mautic">
                    <div class="form-text">The alias or name of the Mautic form.</div>
                </div>
                <div class="mb-3">
                    <label for="mautic_form_post_url" class="form-label">Mautic Form Post URL</label>
                    <input type="url" class="form-control" id="mautic_form_post_url" name="mautic_form_post_url" value="<?= htmlspecialchars($settings['mautic_form_post_url'] ?? '', ENT_QUOTES) ?>" placeholder="https://mautic.example.com/form/submit?formId=...">
                    <div class="form-text">The full URL to post form data to Mautic.</div>
                </div>
            </div>

            <!-- Email Settings Tab -->
            <div class="tab-pane fade p-3" id="email" role="tabpanel" aria-labelledby="email-tab">
                <h5 class="mb-3">Global Email Notification Settings</h5>
                <div class="mb-3">
                    <label for="email_from" class="form-label">Email From Address</label>
                    <input type="email" class="form-control" id="email_from" name="email_from" value="<?= htmlspecialchars($settings['email_from'] ?? '', ENT_QUOTES) ?>" placeholder="from@example.com">
                    <div class="form-text">The email address from which notification emails will be sent.</div>
                </div>
                <div class="mb-3">
                    <label for="email_to" class="form-label">Email To Address</label>
                    <input type="email" class="form-control" id="email_to" name="email_to" value="<?= htmlspecialchars($settings['email_to'] ?? '', ENT_QUOTES) ?>" placeholder="to@example.com">
                    <div class="form-text">The email address to which notification emails will be sent.</div>
                </div>
                <div class="mb-3">
                    <label for="email_subj_prefix" class="form-label">Email Subject Prefix</label>
                    <input type="text" class="form-control" id="email_subj_prefix" name="email_subj_prefix" value="<?= htmlspecialchars($settings['email_subj_prefix'] ?? 'SERP Report', ENT_QUOTES) ?>" placeholder="SERP Report">
                    <div class="form-text">Prefix for the subject line of notification emails.</div>
                </div>

                <hr>
                <h5 class="mb-3">Script-Specific Email Notifications</h5>

                <div class="card card-body mb-3 bg-light">
                    <h6>add_website.php (Manage Domains)</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_add_website_success" name="enable_email_add_website_success" value="1" <?= (isset($settings['enable_email_add_website_success']) && (int)$settings['enable_email_add_website_success'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_add_website_success">Enable success notifications</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_add_website_error" name="enable_email_add_website_error" value="1" <?= (isset($settings['enable_email_add_website_error']) && (int)$settings['enable_email_add_website_error'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_add_website_error">Enable error notifications</label>
                    </div>
                </div>

                <div class="card card-body mb-3 bg-light">
                    <h6>addtomautic.php (Send Emails to Mautic)</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_addtomautic_success" name="enable_email_addtomautic_success" value="1" <?= (isset($settings['enable_email_addtomautic_success']) && (int)$settings['enable_email_addtomautic_success'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_addtomautic_success">Enable success notifications</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_addtomautic_error" name="enable_email_addtomautic_error" value="1" <?= (isset($settings['enable_email_addtomautic_error']) && (int)$settings['enable_email_addtomautic_error'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_addtomautic_error">Enable error notifications</label>
                    </div>
                </div>

                <div class="card card-body mb-3 bg-light">
                    <h6>getURLS.php (Get URLs)</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_geturls_success" name="enable_email_geturls_success" value="1" <?= (isset($settings['enable_email_geturls_success']) && (int)$settings['enable_email_geturls_success'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_geturls_success">Enable success notifications</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_geturls_error" name="enable_email_geturls_error" value="1" <?= (isset($settings['enable_email_geturls_error']) && (int)$settings['enable_email_geturls_error'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_geturls_error">Enable error notifications</label>
                    </div>
                </div>

                <div class="card card-body mb-3 bg-light">
                    <h6>crawler.php (Run Crawler / Get Emails)</h6>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_crawler_success" name="enable_email_crawler_success" value="1" <?= (isset($settings['enable_email_crawler_success']) && (int)$settings['enable_email_crawler_success'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_crawler_success">Enable success notifications</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enable_email_crawler_error" name="enable_email_crawler_error" value="1" <?= (isset($settings['enable_email_crawler_error']) && (int)$settings['enable_email_crawler_error'] === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enable_email_crawler_error">Enable error notifications</label>
                    </div>
                </div>

            </div>
        </div>

        <button type="submit" class="btn btn-primary mt-4">Save All Settings</button>
    </form>
</div>
</body>
</html>