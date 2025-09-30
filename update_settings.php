<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $page_delay_min = $_POST['page_delay_min'] ?? null;
    $page_delay_max = $_POST['page_delay_max'] ?? null;
    $domain_delay_min = $_POST['domain_delay_min'] ?? null;
    $domain_delay_max = $_POST['domain_delay_max'] ?? null;
    $max_depth = $_POST['max_depth'] ?? null;
    $serp_cooldown_hours = $_POST['serp_cooldown_hours'] ?? null;
    $min_pages_crawled = $_POST['min_pages_crawled'] ?? null;
    $mautic_batch_limit = $_POST['mautic_batch_limit'] ?? null;
    $mautic_between = $_POST['mautic_between'] ?? null;
    $mautic_limit_max = $_POST['mautic_limit_max'] ?? null;
    $mautic_api_url = $_POST['mautic_api_url'] ?? null;
    $mautic_api_username = $_POST['mautic_api_username'] ?? null;
    $mautic_seg_id = $_POST['mautic_seg_id'] ?? null;
    $mautic_form_id = $_POST['mautic_form_id'] ?? null;
    $mautic_form_name = $_POST['mautic_form_name'] ?? null;
    $mautic_form_post_url = $_POST['mautic_form_post_url'] ?? null;
    $email_from = $_POST['email_from'] ?? null;
    $email_to = $_POST['email_to'] ?? null;
    $email_subj_prefix = $_POST['email_subj_prefix'] ?? null;

    // Crawler settings
    $results_per_location = $_POST['results_per_location'] ?? null;
    $max_pages = $_POST['max_pages'] ?? null;
    $over_fetch_factor = $_POST['over_fetch_factor'] ?? null;
    $out_dir = $_POST['out_dir'] ?? null;

    // New Email Notification settings (checkboxes: 1 if checked, 0 if not)
    $enable_email_add_website_success = isset($_POST['enable_email_add_website_success']) ? 1 : 0;
    $enable_email_add_website_error = isset($_POST['enable_email_add_website_error']) ? 1 : 0;
    $enable_email_addtomautic_success = isset($_POST['enable_email_addtomautic_success']) ? 1 : 0;
    $enable_email_addtomautic_error = isset($_POST['enable_email_addtomautic_error']) ? 1 : 0;
    $enable_email_geturls_success = isset($_POST['enable_email_geturls_success']) ? 1 : 0;
    $enable_email_geturls_error = isset($_POST['enable_email_geturls_error']) ? 1 : 0;
    $enable_email_crawler_success = isset($_POST['enable_email_crawler_success']) ? 1 : 0;
    $enable_email_crawler_error = isset($_POST['enable_email_crawler_error']) ? 1 : 0;


    $update_fields = [
        'page_delay_min' => (int)$page_delay_min,
        'page_delay_max' => (int)$page_delay_max,
        'domain_delay_min' => (int)$domain_delay_min,
        'domain_delay_max' => (int)$domain_delay_max,
        'max_depth' => (int)$max_depth,
        'serp_cooldown_hours' => (int)$serp_cooldown_hours,
        'min_pages_crawled' => (int)$min_pages_crawled,
        'mautic_batch_limit' => (int)$mautic_batch_limit,
        'mautic_between' => (int)$mautic_between,
        'mautic_limit_max' => (int)$mautic_limit_max,
        'mautic_api_url' => $mautic_api_url,
        'mautic_api_username' => $mautic_api_username,
        'mautic_seg_id' => (int)$mautic_seg_id,
        'mautic_form_id' => (int)$mautic_form_id,
        'mautic_form_name' => $mautic_form_name,
        'mautic_form_post_url' => $mautic_form_post_url,
        'email_from' => $email_from,
        'email_to' => $email_to,
        'email_subj_prefix' => $email_subj_prefix,
        'results_per_location' => (int)$results_per_location,
        'max_pages' => (int)$max_pages,
        'over_fetch_factor' => (int)$over_fetch_factor,
        'out_dir' => $out_dir,
        // Add new email notification fields
        'enable_email_add_website_success' => $enable_email_add_website_success,
        'enable_email_add_website_error' => $enable_email_add_website_error,
        'enable_email_addtomautic_success' => $enable_email_addtomautic_success,
        'enable_email_addtomautic_error' => $enable_email_addtomautic_error,
        'enable_email_geturls_success' => $enable_email_geturls_success,
        'enable_email_geturls_error' => $enable_email_geturls_error,
        'enable_email_crawler_success' => $enable_email_crawler_success,
        'enable_email_crawler_error' => $enable_email_crawler_error,
    ];

    $set_clauses = [];
    $params = [];
    $types = ''; // Initialize types string for bind_param

    foreach ($update_fields as $field => $value) {
        $set_clauses[] = "`{$field}` = ?";
        $params[] = $value;
        // Determine type for bind_param
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    // Special handling for mautic_password if it's provided and not empty
    if (isset($_POST['mautic_password']) && $_POST['mautic_password'] !== '') {
        $set_clauses[] = "`mautic_password` = ?";
        $params[] = $_POST['mautic_password'];
        $types .= 's'; // Password will be a string
    }

    $sql = "UPDATE settings SET " . implode(', ', $set_clauses) . " WHERE id = 1";
    $stmt = $conn->prepare($sql);

    // Use call_user_func_array to bind parameters dynamically
    if (!empty($params)) {
        // mysqli_stmt_bind_param requires parameters to be passed by reference
        $bind_params = [];
        $bind_params[] = $types; // First element is the types string
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key]; // Pass each parameter by reference
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
    }

    if ($stmt->execute()) {
        header('Location: settings.php?updated=1');
    } else {
        error_log("Error updating settings: " . $stmt->error);
        header('Location: settings.php?error=1');
    }
    $stmt->close();
} else {
    header('Location: settings.php');
}
$conn->close();
?>