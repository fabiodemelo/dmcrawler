<?php
include 'auth_check.php';
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Existing settings
    $update_fields = [
        'page_delay_min' => (int)($_POST['page_delay_min'] ?? 0),
        'page_delay_max' => (int)($_POST['page_delay_max'] ?? 0),
        'domain_delay_min' => (int)($_POST['domain_delay_min'] ?? 0),
        'domain_delay_max' => (int)($_POST['domain_delay_max'] ?? 0),
        'max_depth' => (int)($_POST['max_depth'] ?? 20),
        'serp_cooldown_hours' => (int)($_POST['serp_cooldown_hours'] ?? 24),
        'min_pages_crawled' => (int)($_POST['min_pages_crawled'] ?? 0),
        'mautic_batch_limit' => (int)($_POST['mautic_batch_limit'] ?? 6),
        'mautic_between' => (int)($_POST['mautic_between'] ?? 15000000),
        'mautic_limit_max' => (int)($_POST['mautic_limit_max'] ?? 600),
        'mautic_api_url' => $_POST['mautic_api_url'] ?? '',
        'mautic_api_username' => $_POST['mautic_api_username'] ?? '',
        'mautic_seg_id' => (int)($_POST['mautic_seg_id'] ?? 0),
        'mautic_form_id' => (int)($_POST['mautic_form_id'] ?? 0),
        'mautic_form_name' => $_POST['mautic_form_name'] ?? '',
        'mautic_form_post_url' => $_POST['mautic_form_post_url'] ?? '',
        'email_from' => $_POST['email_from'] ?? '',
        'email_to' => $_POST['email_to'] ?? '',
        'email_subj_prefix' => $_POST['email_subj_prefix'] ?? 'SERP Report',
        'results_per_location' => (int)($_POST['results_per_location'] ?? 50),
        'max_pages' => (int)($_POST['max_pages'] ?? 20),
        'over_fetch_factor' => (int)($_POST['over_fetch_factor'] ?? 5),
        'out_dir' => $_POST['out_dir'] ?? 'output',
        // Daily report toggle (auto-add column if missing)
        'enable_daily_report' => isset($_POST['enable_daily_report']) ? 1 : 0,
        // Notification checkboxes
        'enable_email_add_website_success' => isset($_POST['enable_email_add_website_success']) ? 1 : 0,
        'enable_email_add_website_error' => isset($_POST['enable_email_add_website_error']) ? 1 : 0,
        'enable_email_addtomautic_success' => isset($_POST['enable_email_addtomautic_success']) ? 1 : 0,
        'enable_email_addtomautic_error' => isset($_POST['enable_email_addtomautic_error']) ? 1 : 0,
        'enable_email_geturls_success' => isset($_POST['enable_email_geturls_success']) ? 1 : 0,
        'enable_email_geturls_error' => isset($_POST['enable_email_geturls_error']) ? 1 : 0,
        'enable_email_crawler_success' => isset($_POST['enable_email_crawler_success']) ? 1 : 0,
        'enable_email_crawler_error' => isset($_POST['enable_email_crawler_error']) ? 1 : 0,
    ];

    // Auto-add enable_daily_report column if missing
    $_drRes = @$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_daily_report'");
    if (!$_drRes || $_drRes->num_rows === 0) {
        @$conn->query("ALTER TABLE settings ADD COLUMN `enable_daily_report` TINYINT(1) NOT NULL DEFAULT 1");
    }

    // Phase 2 Intelligence fields (only if columns exist)
    $_p2res = @$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'page_quality_threshold'");
    $hasPhase2 = $_p2res && $_p2res->num_rows > 0;
    if ($hasPhase2) {
        $update_fields['page_quality_threshold'] = (int)($_POST['page_quality_threshold'] ?? 30);
        $update_fields['email_confidence_threshold'] = (int)($_POST['email_confidence_threshold'] ?? 40);
        $update_fields['max_fake_ratio'] = (float)($_POST['max_fake_ratio'] ?? 0.60);
        $update_fields['max_consecutive_low_pages'] = (int)($_POST['max_consecutive_low_pages'] ?? 3);
        $update_fields['domain_quality_enabled'] = isset($_POST['domain_quality_enabled']) ? 1 : 0;
    }

    // Special handling for mautic_password
    if (isset($_POST['mautic_password']) && $_POST['mautic_password'] !== '') {
        $update_fields['mautic_password'] = $_POST['mautic_password'];
    }

    $set_clauses = [];
    $params = [];
    $types = '';

    foreach ($update_fields as $field => $value) {
        $set_clauses[] = "`{$field}` = ?";
        $params[] = $value;
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    $sql = "UPDATE settings SET " . implode(', ', $set_clauses) . " WHERE id = 1";
    $stmt = $conn->prepare($sql);

    if ($stmt && !empty($params)) {
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);

        if ($stmt->execute()) {
            header('Location: settings.php?updated=1');
        } else {
            error_log("Error updating settings: " . $stmt->error);
            header('Location: settings.php?error=1');
        }
        $stmt->close();
    } else {
        error_log("Error preparing settings update: " . $conn->error);
        header('Location: settings.php?error=1');
    }
} else {
    header('Location: settings.php');
}
$conn->close();
?>
