<?php
// AJAX toggle API — completely self-contained, no includes that could output anything
if (isset($_GET['ajax_toggle'])) {
    @error_reporting(0);
    @ini_set('display_errors', '0');
    @ob_start();
    @session_start();

    // Auth check inline
    if (empty($_SESSION['loggedin'])) {
        @ob_end_clean();
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'Not authenticated']));
    }

    // Direct DB connection (no include to avoid output leaks)
    $_envFile = __DIR__ . '/.env';
    $_dbHost = 'localhost'; $_dbName = 'demelos'; $_dbUser = 'fabio'; $_dbPass = '';
    if (file_exists($_envFile)) {
        foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_l) {
            if (strpos(trim($_l), '#') === 0) continue;
            if (strpos($_l, '=') !== false) {
                [$_k, $_v] = explode('=', $_l, 2);
                $_k = trim($_k); $_v = trim($_v);
                if ($_k === 'DB_HOST') $_dbHost = $_v;
                elseif ($_k === 'DB_NAME') $_dbName = $_v;
                elseif ($_k === 'DB_USER') $_dbUser = $_v;
                elseif ($_k === 'DB_PASS') $_dbPass = $_v;
            }
        }
    }
    $conn = @new mysqli($_dbHost, $_dbUser, $_dbPass, $_dbName);
    if ($conn->connect_error) {
        @ob_end_clean();
        header('Content-Type: application/json');
        die(json_encode(['ok' => false, 'error' => 'DB connection failed']));
    }

    $id = (int)($_GET['id'] ?? 0);
    $field = $_GET['field'] ?? '';

    @ob_end_clean();
    header('Content-Type: application/json');

    if ($id <= 0 || !in_array($field, ['priority', 'donot', 'archived'])) {
        die(json_encode(['ok' => false, 'error' => 'Invalid params']));
    }

    $res = $conn->query("SELECT `{$field}` FROM domains WHERE id = {$id} LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc())) {
        die(json_encode(['ok' => false, 'error' => 'Domain not found']));
    }

    $newVal = (int)$row[$field] ? 0 : 1;
    $stmt = $conn->prepare("UPDATE domains SET `{$field}` = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param('ii', $newVal, $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    die(json_encode(['ok' => true, 'field' => $field, 'id' => $id, 'value' => $newVal]));
}

// PHP error reporting for debugging 500 errors
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

ob_start(); // Start output buffering to prevent "headers already sent" errors

// add_website.php - Manage Domains (Add, View, Edit, Delete)
include 'auth_check.php'; // For authentication
include 'db.php';         // For database connection ($conn)

// get_setting_value() is already loaded via db.php -> includes/functions.php

// Global email settings (might be retrieved here or from a common config file)
$EMAIL_TO = get_setting_value('email_to') ?? 'admin@example.com';
$EMAIL_FROM = get_setting_value('email_from') ?? 'no-reply@example.com';
$EMAIL_SUBJ_PREFIX = get_setting_value('email_subj_prefix') ?? 'Website Management Report';

// New email notification toggles
$ENABLE_EMAIL_ADD_WEBSITE_SUCCESS = (int)(get_setting_value('enable_email_add_website_success') ?? 1);
$ENABLE_EMAIL_ADD_WEBSITE_ERROR = (int)(get_setting_value('enable_email_add_website_error') ?? 1);


// Helper for redirecting while preserving query parameters
function redirect_self(array $params = []): void {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $query = array_merge($_GET, $params);
    // Remove action/id/msg params from previous GET if present to avoid pollution
    unset($query['action'], $query['id'], $query['msg_type'], $query['msg_text']);
    header('Location: ' . $base . (empty($query) ? '' : ('?' . http_build_query($query))));
    exit;
}

$message = '';

// Auto-create archived column if missing
$_arRes = @$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'archived'");
if (!$_arRes || $_arRes->num_rows === 0) {
    @$conn->query("ALTER TABLE domains ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("CREATE INDEX idx_archived ON domains (archived)");
}

// Handle POST actions (Add/Update/Delete Domain)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $conn;
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $newDomain = trim($_POST['domain'] ?? '');
        if (!empty($newDomain)) {
            // Basic validation for domain format
            if (!filter_var('http://' . $newDomain, FILTER_VALIDATE_URL)) { // Prepend scheme for validation
                $message = '<div class="alert alert-danger">Error: Invalid domain format.</div>';
            } else {
                // Check for existing domain using a distinct statement object
                $checkStmt = $conn->prepare("SELECT id FROM domains WHERE domain = ? LIMIT 1");
                if ($checkStmt) {
                    $checkStmt->bind_param('s', $newDomain);
                    $checkStmt->execute();
                    $checkStmt->store_result(); // Store results for num_rows
                    
                    if ($checkStmt->num_rows > 0) {
                        $message = '<div class="alert alert-warning">Domain already exists.</div>';
                    } else {
                        // Domain does not exist, proceed with insert
                        // Prepare the INSERT statement using a distinct statement object
                        // Included 'donot' with default value 0
                        $insertStmt = $conn->prepare("INSERT INTO domains (domain, crawled, date_added, emails_found, urls_crawled, priority, donot) VALUES (?, 0, NOW(), 0, 0, 0, 0)");
                        if ($insertStmt) {
                            $insertStmt->bind_param('s', $newDomain);
                            if ($insertStmt->execute()) {
                                $message = '<div class="alert alert-success">Domain "' . htmlspecialchars($newDomain) . '" added successfully.</div>';
                            } else {
                                $message = '<div class="alert alert-danger">Error adding domain: ' . $insertStmt->error . '</div>';
                                error_log("Error adding domain: " . $insertStmt->error);
                            }
                            $insertStmt->close(); // Close the INSERT statement
                        } else {
                            $message = '<div class="alert alert-danger">Error preparing add statement: ' . $conn->error . '</div>';
                            error_log("Error preparing add statement: " . $conn->error);
                        }
                    }
                    $checkStmt->close(); // Close the SELECT statement here, after its logic is complete
                } else {
                    $message = '<div class="alert alert-danger">Error preparing domain check: ' . $conn->error . '</div>';
                    error_log("Error preparing domain check: " . $conn->error);
                }
            }
        } else {
            $message = '<div class="alert alert-warning">Domain name cannot be empty.</div>';
        }
    } elseif ($action === 'update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $updatedDomain = trim($_POST['domain'] ?? '');
        $crawled = isset($_POST['crawled']) && $_POST['crawled'] === '1' ? 1 : 0;
        $priority = isset($_POST['priority']) ? 1 : 0; 
        $donot = isset($_POST['donot']) ? 1 : 0; // Capture donot from checkbox

        if ($id > 0 && !empty($updatedDomain)) {
            if (!filter_var('http://' . $updatedDomain, FILTER_VALIDATE_URL)) {
                $message = '<div class="alert alert-danger">Error: Invalid domain format for ID ' . $id . '.</div>';
            } else {
                // Check if the domain already exists for another ID
                $checkExistingStmt = $conn->prepare("SELECT id FROM domains WHERE domain = ? AND id != ? LIMIT 1");
                if ($checkExistingStmt) {
                    $checkExistingStmt->bind_param('si', $updatedDomain, $id);
                    $checkExistingStmt->execute();
                    $checkExistingStmt->store_result();
                    
                    if ($checkExistingStmt->num_rows > 0) {
                        $message = '<div class="alert alert-warning">Domain "' . htmlspecialchars($updatedDomain) . '" already exists for another entry.</div>';
                    } else {
                        // Domain is unique or belongs to the current ID, proceed with update
                        // Use a distinct variable for the update statement
                        // Included 'donot' in the UPDATE statement
                        $updateStmt = $conn->prepare("UPDATE domains SET domain = ?, crawled = ?, priority = ?, donot = ? WHERE id = ? LIMIT 1");
                        if ($updateStmt) {
                            $updateStmt->bind_param('siiii', $updatedDomain, $crawled, $priority, $donot, $id);
                            if ($updateStmt->execute()) {
                                $message = '<div class="alert alert-success">Domain ID ' . $id . ' updated successfully.</div>';
                            } else {
                                $message = '<div class="alert alert-danger">Error updating domain ID ' . $id . ': ' . $updateStmt->error . '</div>';
                                error_log("Error updating domain ID {$id}: " . $updateStmt->error);
                            }
                            $updateStmt->close(); // Close the UPDATE statement
                        } else {
                            $message = '<div class="alert alert-danger">Error preparing update statement for ID ' . $id . ': ' . $conn->error . '</div>';
                            error_log("Error preparing update statement for ID {$id}: " . $conn->error);
                        }
                    }
                } else {
                    $message = '<div class="alert alert-danger">Error preparing domain check for update: ' . $conn->error . '</div>';
                    error_log("Error preparing domain check for update: " . $conn->error);
                }
            }
        } else {
            $message = '<div class="alert alert-danger">Error: Invalid ID or domain provided for update.</div>';
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM domains WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Domain ID ' . $id . ' deleted successfully.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error deleting domain ID ' . $id . ': ' . $stmt->error . '</div>';
                    error_log("Error deleting domain ID {$id}: " . $stmt->error);
                }
                $stmt->close();
            } else {
                $message = '<div class="alert alert-danger">Error preparing delete statement for ID ' . $id . ': ' . $conn->error . '</div>';
                error_log("Error preparing delete statement for ID {$id}: " . $conn->error);
            }
        } else {
            $message = '<div class="alert alert-danger">Error: Invalid ID provided for deletion.</div>';
        }
    } elseif ($action === 'archive_uncrawled') {
        // Bulk archive all uncrawled domains (optionally filtered by campaign)
        $archCampaign = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
        $archWhere = "crawled = 0 AND (archived = 0 OR archived IS NULL)";
        if ($archCampaign > 0) {
            $archWhere .= " AND campaign_id = " . $archCampaign;
        }
        $archResult = $conn->query("UPDATE domains SET archived = 1 WHERE {$archWhere}");
        if ($archResult) {
            $affected = $conn->affected_rows;
            $message = '<div class="alert alert-success">Archived ' . $affected . ' uncrawled domain' . ($affected !== 1 ? 's' : '') . '.</div>';
        } else {
            $message = '<div class="alert alert-danger">Error archiving domains: ' . $conn->error . '</div>';
            error_log("Error bulk archiving: " . $conn->error);
        }
    }
    // Ensure `p`, `orderBy`, `orderDir`, `status` are preserved on redirect for form submissions
    $redirect_params = $_GET;
    unset($redirect_params['action'], $redirect_params['id'], $redirect_params['msg_type'], $redirect_params['msg_text']); // Clean existing
    $redirect_params['msg_type'] = (strpos($message, 'danger') !== false || strpos($message, 'warning') !== false ? 'danger' : 'success');
    $redirect_params['msg_text'] = strip_tags($message);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($redirect_params));
    exit;
}

// Display messages from redirect
if (isset($_GET['msg_type']) && isset($_GET['msg_text'])) {
    $message = '<div class="alert alert-' . htmlspecialchars($_GET['msg_type']) . '">' . htmlspecialchars($_GET['msg_text']) . '</div>';
}

    global $conn;
    // Filters
    $filterStatus = $_GET['status'] ?? 'all';
    $filterCrawled = $_GET['crawled_filter'] ?? 'all';
    $filterPriority = $_GET['priority_filter'] ?? 'all';
    $filterDonot = $_GET['donot_filter'] ?? 'all';
    $filterArchived = $_GET['archived_filter'] ?? 'active'; // default: hide archived
    $filterCampaign = $_GET['filter_campaign'] ?? '';
    $filterKeyword = $_GET['filter_keyword'] ?? '';
    $filterLocation = $_GET['filter_location'] ?? '';
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

    // Pagination setup
    $records_per_page = 50;
    $current_page = (int)(isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1);
    $offset = (int)(($current_page - 1) * $records_per_page);

    // Sorting setup
    $orderBy = $_GET['orderBy'] ?? 'date_added';
    $orderDir = $_GET['orderDir'] ?? 'DESC';
    $validOrderColumns = ['id', 'domain', 'crawled', 'date_added', 'date_crawled', 'emails_found', 'urls_crawled', 'priority', 'donot', 'archived'];
    if (!in_array($orderBy, $validOrderColumns)) {
        $orderBy = 'date_added';
    }
    if (!in_array(strtoupper($orderDir), ['ASC', 'DESC'])) {
        $orderDir = 'DESC';
    }
    $orderByClause = "d.`$orderBy` $orderDir";

    // Build WHERE clause from all filters
    $whereParts = [];

    if ($filterStatus === 'pending') {
        $whereParts[] = 'd.crawled = 0';
    } elseif ($filterStatus === 'crawled') {
        $whereParts[] = 'd.crawled = 1';
    }

    if ($filterCrawled === 'yes') {
        $whereParts[] = 'd.date_crawled IS NOT NULL';
    } elseif ($filterCrawled === 'no') {
        $whereParts[] = 'd.date_crawled IS NULL';
    }

    if ($filterPriority === 'high') {
        $whereParts[] = 'd.priority = 1';
    } elseif ($filterPriority === 'normal') {
        $whereParts[] = 'd.priority = 0';
    }

    if ($filterDonot === 'skip') {
        $whereParts[] = 'd.donot = 1';
    } elseif ($filterDonot === 'crawl') {
        $whereParts[] = 'd.donot = 0';
    }

    // Archived filter (default: show only active)
    if ($filterArchived === 'active') {
        $whereParts[] = '(d.archived = 0 OR d.archived IS NULL)';
    } elseif ($filterArchived === 'archived') {
        $whereParts[] = 'd.archived = 1';
    }
    // 'all' = no filter

    if ($filterCampaign !== '') {
        $whereParts[] = "d.campaign_id = " . (int)$filterCampaign;
    }
    if ($filterKeyword !== '') {
        $ek = $conn->real_escape_string($filterKeyword);
        $whereParts[] = "d.source_keyword = '{$ek}'";
    }
    if ($filterLocation !== '') {
        $el = $conn->real_escape_string($filterLocation);
        $whereParts[] = "d.source_location = '{$el}'";
    }

    if ($search !== '') {
        $s = $conn->real_escape_string($search);
        $whereParts[] = "d.domain LIKE '%{$s}%'";
    }

    $whereClause = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Get extra stats: Total domains yet to crawl
    $totalDomainsYetToCrawl = 0;
    $resStats = $conn->query("SELECT COUNT(*) AS total FROM domains WHERE crawled = 0 AND donot = 0 AND (archived = 0 OR archived IS NULL)");
    if ($resStats) {
        $totalDomainsYetToCrawl = (int)$resStats->fetch_assoc()['total'];
    }

    // Get total number of records for the current filter
    $count_sql = "SELECT COUNT(*) AS total FROM domains d LEFT JOIN campaigns c ON d.campaign_id = c.id {$whereClause}";
    $count_result = $conn->query($count_sql);
    $total_records = 0;
    if ($count_result) {
        $total_records = (int)$count_result->fetch_assoc()['total'];
    } else {
        error_log("Error counting records for pagination: " . $conn->error . " SQL: " . $count_sql);
    }

    $total_pages = (int)ceil($total_records / $records_per_page);

    // Fetch domains for display with filter, sorting, and pagination
    $domains = [];
    $sql = "SELECT d.id, d.domain, d.crawled, d.date_added, d.date_crawled, d.emails_found, d.urls_crawled, d.priority, d.donot, d.archived,
                   d.campaign_id, d.source_keyword, d.source_location, c.name AS campaign_name
            FROM domains d
            LEFT JOIN campaigns c ON d.campaign_id = c.id
            {$whereClause}
            ORDER BY {$orderByClause}
            LIMIT {$records_per_page} OFFSET {$offset}";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $domains[] = $row;
        }
        $result->free();
    } else {
        $message = '<div class="alert alert-danger">Error fetching domains: ' . $conn->error . '</div>';
        error_log("Error fetching domains with pagination: " . $conn->error . " SQL: " . $sql);
    }

    // Fetch filter dropdown options
    $campaignsOpts = [];
    $r = $conn->query("SELECT id, name FROM campaigns ORDER BY name ASC");
    if ($r) { while ($row = $r->fetch_assoc()) $campaignsOpts[] = $row; }

    $keywordsOpts = [];
    $r = $conn->query("SELECT DISTINCT source_keyword FROM domains WHERE source_keyword IS NOT NULL AND source_keyword != '' ORDER BY source_keyword ASC");
    if ($r) { while ($row = $r->fetch_assoc()) $keywordsOpts[] = $row['source_keyword']; }

    $locationsOpts = [];
    $r = $conn->query("SELECT DISTINCT source_location FROM domains WHERE source_location IS NOT NULL AND source_location != '' ORDER BY source_location ASC");
    if ($r) { while ($row = $r->fetch_assoc()) $locationsOpts[] = $row['source_location']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domains — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header d-flex justify-content-between align-items-start">
        <div>
            <h1>Domains</h1>
            <p>Manage crawling targets. <strong><?= number_format($totalDomainsYetToCrawl) ?></strong> domains pending crawl. <strong><?= number_format($total_records) ?></strong> matching current filters.</p>
        </div>
        <form method="get" id="filterForm" class="d-flex align-items-center gap-2 mt-2">
            <input type="text" name="search" class="form-control" style="width:260px" placeholder="Filter by domain..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" onkeydown="if(event.key==='Enter'){this.form.p.value=1; this.form.submit();}">
            <button type="submit" class="btn btn-primary" onclick="this.form.p.value=1;"><i class="fas fa-search"></i></button>
            <?php
                $exportParams = http_build_query(array_filter([
                    'status' => $filterStatus, 'crawled_filter' => $filterCrawled,
                    'priority_filter' => $filterPriority, 'donot_filter' => $filterDonot,
                    'archived_filter' => $filterArchived,
                    'filter_campaign' => $filterCampaign, 'filter_keyword' => $filterKeyword,
                    'filter_location' => $filterLocation, 'search' => $search,
                ]));
            ?>
            <a class="btn btn-success" href="export_domains.php?<?= $exportParams ?>" title="Export CSV"><i class="fas fa-download"></i></a>
            <a class="btn btn-outline-secondary" href="add_website.php" onclick="['status','crawled_filter','priority_filter','donot_filter','archived_filter','filter_campaign','filter_keyword','filter_location'].forEach(function(f){document.cookie='dmf_'+f+'=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;SameSite=Lax';});"><i class="fas fa-times"></i></a>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <input type="hidden" name="crawled_filter" value="<?= htmlspecialchars($filterCrawled) ?>">
            <input type="hidden" name="priority_filter" value="<?= htmlspecialchars($filterPriority) ?>">
            <input type="hidden" name="donot_filter" value="<?= htmlspecialchars($filterDonot) ?>">
            <input type="hidden" name="filter_campaign" value="<?= htmlspecialchars($filterCampaign) ?>">
            <input type="hidden" name="filter_keyword" value="<?= htmlspecialchars($filterKeyword) ?>">
            <input type="hidden" name="filter_location" value="<?= htmlspecialchars($filterLocation) ?>">
            <input type="hidden" name="archived_filter" value="<?= htmlspecialchars($filterArchived) ?>">
            <input type="hidden" name="orderBy" value="<?= htmlspecialchars($orderBy) ?>">
            <input type="hidden" name="orderDir" value="<?= htmlspecialchars($orderDir) ?>">
            <input type="hidden" name="p" value="<?= htmlspecialchars($current_page) ?>">
        </form>
    </div>

    <!-- Secondary filters: Campaign, Keyword, Location, Archived -->
    <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
        <select class="form-select form-select-sm table-filter" data-filter="filter_campaign" style="width:auto; min-width:140px;">
            <option value="">All Campaigns</option>
            <?php foreach ($campaignsOpts as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCampaign == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm table-filter" data-filter="filter_keyword" style="width:auto; min-width:140px;">
            <option value="">All Keywords</option>
            <?php foreach ($keywordsOpts as $kw): ?>
            <option value="<?= htmlspecialchars($kw) ?>" <?= $filterKeyword === $kw ? 'selected' : '' ?>><?= htmlspecialchars($kw) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm table-filter" data-filter="filter_location" style="width:auto; min-width:140px;">
            <option value="">All Locations</option>
            <?php foreach ($locationsOpts as $loc): ?>
            <option value="<?= htmlspecialchars($loc) ?>" <?= $filterLocation === $loc ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm table-filter" data-filter="archived_filter" style="width:auto; min-width:140px;">
            <option value="active" <?= $filterArchived === 'active' ? 'selected' : '' ?>>Active Only</option>
            <option value="archived" <?= $filterArchived === 'archived' ? 'selected' : '' ?>>Archived Only</option>
            <option value="all" <?= $filterArchived === 'all' ? 'selected' : '' ?>>All (incl. Archived)</option>
        </select>

        <div class="ms-auto">
            <form method="post" action="add_website.php" class="d-inline" onsubmit="return confirm('Archive all uncrawled domains? They will be hidden from crawler and default view.');">
                <input type="hidden" name="action" value="archive_uncrawled">
                <?php if ($filterCampaign): ?>
                <input type="hidden" name="campaign_id" value="<?= (int)$filterCampaign ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-archive me-1"></i>Archive Uncrawled<?= $filterCampaign ? ' (filtered campaign)' : '' ?></button>
            </form>
        </div>
    </div>

    <?= $message ?>

    <div class="card mb-4">
        <div class="card-body">
            <h3 class="mb-3">Add New Domain</h3>
            <form method="post" action="add_website.php" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add">
                <div class="col-md-9">
                    <label class="form-label">Domain</label>
                    <input type="text" name="domain" class="form-control" placeholder="example.com" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Add Domain</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card">
        <table class="table table-fixed-layout">
            <colgroup>
                <col style="width:55px;">
                <col>
                <col style="width:90px;">
                <col style="width:90px;">
                <col style="width:90px;">
                <col style="width:50px;">
                <col style="width:50px;">
                <col style="width:90px;">
                <col style="width:90px;">
                <col style="width:80px;">
                <col style="width:150px;">
                <col style="width:130px;">
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th class="domain-column">Domain</th>
                    <th>
                        <select class="form-select form-select-sm table-filter" data-filter="status">
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Status</option>
                            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="crawled" <?= $filterStatus === 'crawled' ? 'selected' : '' ?>>Crawled</option>
                        </select>
                    </th>
                    <th>Added</th>
                    <th>
                        <select class="form-select form-select-sm table-filter" data-filter="crawled_filter">
                            <option value="all" <?= $filterCrawled === 'all' ? 'selected' : '' ?>>Crawled</option>
                            <option value="yes" <?= $filterCrawled === 'yes' ? 'selected' : '' ?>>Yes</option>
                            <option value="no" <?= $filterCrawled === 'no' ? 'selected' : '' ?>>No</option>
                        </select>
                    </th>
                    <th>URLs</th>
                    <th>Emails</th>
                    <th>
                        <select class="form-select form-select-sm table-filter" data-filter="priority_filter">
                            <option value="all" <?= $filterPriority === 'all' ? 'selected' : '' ?>>Priority</option>
                            <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="high" <?= $filterPriority === 'high' ? 'selected' : '' ?>>High</option>
                        </select>
                    </th>
                    <th>
                        <select class="form-select form-select-sm table-filter" data-filter="donot_filter">
                            <option value="all" <?= $filterDonot === 'all' ? 'selected' : '' ?>>DoNot</option>
                            <option value="crawl" <?= $filterDonot === 'crawl' ? 'selected' : '' ?>>Crawl</option>
                            <option value="skip" <?= $filterDonot === 'skip' ? 'selected' : '' ?>>Skip</option>
                        </select>
                    </th>
                    <th>
                        <select class="form-select form-select-sm table-filter" data-filter="archived_filter">
                            <option value="active" <?= $filterArchived === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="archived" <?= $filterArchived === 'archived' ? 'selected' : '' ?>>Archived</option>
                            <option value="all" <?= $filterArchived === 'all' ? 'selected' : '' ?>>All</option>
                        </select>
                    </th>
                    <th>Source</th>
                    <th class="actions-col text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($domains)): ?>
                <tr><td colspan="12">
                    <div class="empty-state">
                        <i class="fas fa-globe"></i>
                        <h4>No domains found</h4>
                        <p>No domains match the current filter. Try changing filters or add a new domain.</p>
                    </div>
                </td></tr>
                <?php else: ?>
                <?php foreach ($domains as $row): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td class="domain-column">
                        <a href="http://<?= htmlspecialchars($row['domain']) ?>" target="_blank" class="text-decoration-none">
                            <?= htmlspecialchars($row['domain']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($row['crawled']): ?>
                        <span class="badge bg-success">Crawled</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= date('Y-m-d', strtotime($row['date_added'])) ?></td>
                    <td class="small"><?= $row['date_crawled'] ? date('Y-m-d', strtotime($row['date_crawled'])) : '-' ?></td>
                    <td><?= number_format($row['urls_crawled']) ?></td>
                    <td><?= number_format($row['emails_found']) ?></td>
                    <td>
                        <span class="badge toggle-badge js-toggle <?= $row['priority'] ? 'bg-warning text-dark' : 'bg-light text-muted' ?>"
                              data-id="<?= $row['id'] ?>"
                              data-field="priority"
                              data-value="<?= $row['priority'] ?>"
                              style="cursor:pointer;user-select:none;">
                            <?= $row['priority'] ? 'High' : 'Normal' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge toggle-badge js-toggle <?= $row['donot'] ? 'bg-danger' : 'bg-light text-muted' ?>"
                              data-id="<?= $row['id'] ?>"
                              data-field="donot"
                              data-value="<?= $row['donot'] ?>"
                              style="cursor:pointer;user-select:none;">
                            <?= $row['donot'] ? 'Skip' : 'Crawl' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge toggle-badge js-toggle <?= !empty($row['archived']) ? 'bg-secondary' : 'bg-light text-muted' ?>"
                              data-id="<?= $row['id'] ?>"
                              data-field="archived"
                              data-value="<?= (int)($row['archived'] ?? 0) ?>"
                              style="cursor:pointer;user-select:none;">
                            <?= !empty($row['archived']) ? 'Archived' : 'Active' ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $srcParts = array_filter([
                            $row['campaign_name'] ?? '',
                            $row['source_location'] ?? '',
                            $row['source_keyword'] ?? '',
                        ]);
                        ?>
                        <?php if (!empty($srcParts)): ?>
                        <span style="font-size:0.7rem; line-height:1.2; display:block; color:#94a3b8;"><?= htmlspecialchars(implode(' > ', $srcParts)) ?></span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:0.7rem;">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <a href="run_crawler.php?domain_id=<?= $row['id'] ?>" class="btn btn-outline-success" title="Crawl Now" onclick="return confirm('Start crawling <?= htmlspecialchars($row['domain']) ?> now?');">
                                <i class="fas fa-spider"></i>
                            </a>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $row['id'] ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>

                        <!-- Edit Modal -->
                        <div class="modal fade" id="editModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <form method="post" action="add_website.php">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title text-dark">Edit Domain #<?= $row['id'] ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body text-start text-dark">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Domain Name</label>
                                                <input type="text" name="domain" class="form-control" value="<?= htmlspecialchars($row['domain']) ?>" required>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="crawled" value="1" id="crawledCheck<?= $row['id'] ?>" <?= $row['crawled'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="crawledCheck<?= $row['id'] ?>">Mark as Crawled</label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="priority" value="1" id="priorityCheck<?= $row['id'] ?>" <?= $row['priority'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="priorityCheck<?= $row['id'] ?>">High Priority</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="donot" value="1" id="donotCheck<?= $row['id'] ?>" <?= $row['donot'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="donotCheck<?= $row['id'] ?>">Do Not Crawl</label>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Delete Modal -->
                        <div class="modal fade" id="deleteModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <form method="post" action="add_website.php">
                                    <div class="modal-content text-dark">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Confirm Delete</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body text-start">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            Are you sure you want to delete <strong><?= htmlspecialchars($row['domain']) ?></strong>?
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Delete Domain</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1):
        $pq = http_build_query([
            'status' => $filterStatus, 'crawled_filter' => $filterCrawled,
            'priority_filter' => $filterPriority, 'donot_filter' => $filterDonot,
            'archived_filter' => $filterArchived,
            'filter_campaign' => $filterCampaign, 'filter_keyword' => $filterKeyword,
            'filter_location' => $filterLocation,
            'search' => $search, 'orderBy' => $orderBy, 'orderDir' => $orderDir,
        ]);
    ?>
    <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm justify-content-center mt-3">
            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=1&<?= $pq ?>">First</a>
            </li>
            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=<?= intval($current_page) - 1 ?>&<?= $pq ?>">Prev</a>
            </li>
            <?php
            $start = max(1, intval($current_page) - 2);
            $end = min(intval($total_pages), intval($current_page) + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                <a class="page-link" href="?p=<?= $i ?>&<?= $pq ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=<?= intval($current_page) + 1 ?>&<?= $pq ?>">Next</a>
            </li>
            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=<?= $total_pages ?>&<?= $pq ?>">Last</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script data-cfasync="false">
// --- Cookie helpers ---
function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
}
function getCookie(name) {
    var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
}

// --- Table header filter dropdowns ---
document.querySelectorAll('.table-filter').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var filterName = this.dataset.filter;
        var val = this.value;
        // Save to cookie
        setCookie('dmf_' + filterName, val, 90);
        // Update the hidden input in filterForm and submit
        var form = document.getElementById('filterForm');
        var hidden = form.querySelector('input[name="' + filterName + '"]');
        if (hidden) hidden.value = val;
        form.querySelector('input[name="p"]').value = '1';
        form.submit();
    });
});

// --- On page load: restore filters from cookies if no explicit URL param ---
(function() {
    var params = new URLSearchParams(window.location.search);
    var filters = ['status', 'crawled_filter', 'priority_filter', 'donot_filter', 'archived_filter', 'filter_campaign', 'filter_keyword', 'filter_location'];
    var needsRedirect = false;
    var form = document.getElementById('filterForm');

    // Only apply cookies if there are NO filter params at all in the URL (fresh visit)
    var hasAnyFilter = filters.some(function(f) { return params.has(f); });
    if (!hasAnyFilter) {
        filters.forEach(function(f) {
            var saved = getCookie('dmf_' + f);
            if (saved && saved !== 'all') {
                params.set(f, saved);
                needsRedirect = true;
            }
        });
        if (needsRedirect) {
            window.location.search = params.toString();
        }
    }
})();

// --- Toggle badges (priority/donot) ---
document.addEventListener('click', function(e) {
    var el = e.target.closest('.js-toggle');
    if (!el) return;

    var id = el.dataset.id;
    var field = el.dataset.field;

    el.style.opacity = '0.5';
    el.style.pointerEvents = 'none';

    fetch('add_website.php?ajax_toggle=1&id=' + id + '&field=' + field, { cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            el.style.opacity = '1';
            el.style.pointerEvents = '';
            if (!data.ok) { alert('Error: ' + (data.error || 'Unknown')); return; }

            var v = data.value;
            el.dataset.value = v;

            if (field === 'priority') {
                el.textContent = v ? 'High' : 'Normal';
                el.className = 'badge toggle-badge js-toggle ' + (v ? 'bg-warning text-dark' : 'bg-light text-muted');
            } else if (field === 'donot') {
                el.textContent = v ? 'Skip' : 'Crawl';
                el.className = 'badge toggle-badge js-toggle ' + (v ? 'bg-danger' : 'bg-light text-muted');
            } else if (field === 'archived') {
                el.textContent = v ? 'Archived' : 'Active';
                el.className = 'badge toggle-badge js-toggle ' + (v ? 'bg-secondary' : 'bg-light text-muted');
            }

            el.style.transform = 'scale(1.2)';
            setTimeout(function() { el.style.transform = ''; }, 200);
        })
        .catch(function(e) {
            el.style.opacity = '1';
            el.style.pointerEvents = '';
            alert('Network error');
        });
});
</script>
<style>
.toggle-badge {
    transition: all 0.2s ease;
    padding: 0.4em 0.75em;
    font-size: 0.8rem;
}
.toggle-badge:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.table-fixed-layout {
    table-layout: fixed;
    width: 100%;
}
.table-filter {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.15);
    color: inherit;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.15rem 0.3rem;
    cursor: pointer;
    width: 100%;
    box-sizing: border-box;
}
.table-filter:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(79,70,229,0.25);
}
.table-filter option {
    background: #1e293b;
    color: #e2e8f0;
}
</style>
</body>
</html>
<?php ob_end_flush(); ?>