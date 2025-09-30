<?php
// PHP error reporting for debugging 500 errors
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// add_website.php - Manage Domains (Add, View, Edit, Delete)
include 'auth_check.php'; // For authentication
include 'db.php';         // For database connection ($conn)

// Helper to read a single setting field safely
if (!function_exists('get_setting_value')) {
    function get_setting_value($field) {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) return null;
        global $conn;
        if (!($conn instanceof mysqli) || !$conn->ping()) {
            error_log("get_setting_value: DB connection lost for field '{$field}'");
            return null;
        }
        $res = $conn->query("SELECT `{$field}` AS v FROM settings WHERE id = 1");
        if ($res) {
            if ($row = $res->fetch_assoc()) {
                return $row['v'];
            }
        } else {
            error_log("get_setting_value: Query failed for field '{$field}': " . $conn->error);
        }
        return null;
    }
}

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

// Handle POST actions (Add/Update/Delete Domain)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

// Handle filtering by status
$filterStatus = $_GET['status'] ?? 'pending'; // Default to 'pending'
$crawledFilter = '';
if ($filterStatus === 'crawled') {
    $crawledFilter = 'WHERE crawled = 1';
} elseif ($filterStatus === 'pending') {
    $crawledFilter = 'WHERE crawled = 0';
} elseif ($filterStatus === 'all') {
    $crawledFilter = ''; // No filter for 'all'
}

// Fetch total Domains yet to be crawled (corrected metric)
$totalDomainsYetToCrawl = 0;
// Count the number of domains that are marked as not yet crawled
$resDomainsPending = $conn->query("SELECT COUNT(*) AS total_domains_pending FROM domains WHERE crawled = 0");
if ($resDomainsPending) {
    if ($row = $resDomainsPending->fetch_assoc()) {
        $totalDomainsYetToCrawl = (int)$row['total_domains_pending'];
    }
} else {
    error_log("Error fetching total domains pending: " . $conn->error);
}


// Pagination setup
$records_per_page = 100; // Number of records to display per page
$current_page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($current_page < 1) $current_page = 1;

// Get total number of records for the current filter
$count_sql = "SELECT COUNT(*) AS total FROM domains {$crawledFilter}";
$count_result = $conn->query($count_sql);
$total_records = 0;
if ($count_result) {
    $total_records = $count_result->fetch_assoc()['total'];
} else {
    error_log("Error counting records for pagination: " . $conn->error . " SQL: " . $count_sql);
}

$total_pages = ceil($total_records / $records_per_page);
// Ensure current page is not greater than total pages if records are deleted
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}
if ($total_pages == 0) { // If there are no records, ensure current_page is 1
    $current_page = 1;
}


// Calculate the OFFSET for the SQL query
$offset = ($current_page - 1) * $records_per_page;
if ($offset < 0) $offset = 0; // Ensure offset is not negative

// Function to generate sorting links
// Updated to include 'p' parameter
function sort_link($column, $text, $currentOrderBy, $currentOrderDir, $filterStatus, $currentPage) {
    $dir = ($column == $currentOrderBy && $currentOrderDir == 'ASC') ? 'DESC' : 'ASC';
    $icon = ($column == $currentOrderBy) ? ($currentOrderDir == 'ASC' ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>') : '';
    $params = $_GET;
    $params['orderBy'] = $column;
    $params['orderDir'] = $dir;
    $params['status'] = $filterStatus; // Preserve status filter
    $params['p'] = $currentPage; // Preserve current page
    // Remove temporary message params that are not part of navigation/sorting
    unset($params['action'], $params['id'], $params['msg_type'], $params['msg_text']);
    $queryStr = http_build_query($params);
    return "<a href='?{$queryStr}'>$text$icon</a>";
}

// Determine the ORDER BY clause
$orderByParam = $_GET['orderBy'] ?? null;
$orderDirParam = $_GET['orderDir'] ?? 'DESC';
// Added 'donot' to valid sortable columns
$valid_order_columns = ['id', 'domain', 'crawled', 'date_added', 'date_crawled', 'emails_found', 'urls_crawled', 'priority', 'donot'];

if ($orderByParam && in_array($orderByParam, $valid_order_columns)) {
    $orderByClause = "{$orderByParam} " . (in_array(strtoupper($orderDirParam), ['ASC', 'DESC']) ? $orderDirParam : 'DESC');
    $orderBy = $orderByParam; // For highlighting current sort
    $orderDir = $orderDirParam; // For highlighting current sort
} else {
    // Default sorting: pending (crawled=0) first, then by priority (1 before 0), then by id DESC
    $orderByClause = "crawled ASC, priority DESC, id DESC";
    $orderBy = 'crawled_priority_default'; // A dummy value to indicate default sort is active
    $orderDir = 'DESC'; // Default direction
}


// Fetch domains for display with filter, sorting, and pagination
$domains = [];
// Added 'donot' to the SELECT clause
$sql = "SELECT id, domain, crawled, date_added, date_crawled, emails_found, urls_crawled, priority, donot FROM domains {$crawledFilter} ORDER BY {$orderByClause} LIMIT {$records_per_page} OFFSET {$offset}";
error_log("Executing SQL: " . $sql); // Log the executed SQL query
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
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Domains</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table td, .table th { vertical-align: middle; }
        .form-control-sm-inline {
            width: 100%;
        }
        .domain-column {
            max-width: 300px; /* Set max width for domain column */
            word-wrap: break-word; /* Break long words */
        }
        .actions-col { width: 120px; }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Manage Domains <small class="text-muted">(Total Domains Yet to Crawl: <?= number_format($totalDomainsYetToCrawl) ?>)</small></h1>
        <form method="get" class="d-flex align-items-center">
            <label for="statusFilter" class="me-2 mb-0">Show:</label>
            <select name="status" id="statusFilter" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="crawled" <?= $filterStatus === 'crawled' ? 'selected' : '' ?>>Crawled</option>
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
            </select>
            <input type="hidden" name="orderBy" value="<?= htmlspecialchars($orderBy) ?>">
            <input type="hidden" name="orderDir" value="<?= htmlspecialchars($orderDir) ?>">
            <input type="hidden" name="p" value="<?= htmlspecialchars($current_page) ?>"> <!-- Preserve page on filter change -->
        </form>
    </div>

    <?= $message ?>

    <div class="card mb-4">
        <div class="card-header">
            Add New Domain
        </div>
        <div class="card-body">
            <form method="post" class="row g-3 align-items-center">
                <input type="hidden" name="action" value="add">
                <div class="col-md-8">
                    <label for="newDomain" class="visually-hidden">New Domain</label>
                    <input type="text" class="form-control" id="newDomain" name="domain" placeholder="e.g., example.com" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus"></i> Add Domain</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover table-sm">
            <thead class="table-success">
            <tr>
                <th><?= sort_link('id', 'ID', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th class="domain-column"><?= sort_link('domain', 'Domain', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('crawled', 'Crawled?', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('date_added', 'Date Added', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('date_crawled', 'Last Crawled', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('emails_found', 'Emails', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('urls_crawled', 'URLs', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('priority', 'Priority', $orderBy, $orderDir, $filterStatus, $current_page) ?></th>
                <th><?= sort_link('donot', 'Do Not Crawl', $orderBy, $orderDir, $filterStatus, $current_page) ?></th> <!-- New table header for Donot -->
                <th class="text-end actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($domains)): ?>
                <?php foreach ($domains as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td class="domain-column">
                            <form id="updateForm_<?= $row['id'] ?>" method="post" class="d-inline-block w-100">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                <input type="text" name="domain" class="form-control form-control-sm-inline" value="<?= htmlspecialchars($row['domain']) ?>" required>
                            </form>
                        </td>
                        <td>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="crawledSwitch_<?= $row['id'] ?>" form="updateForm_<?= $row['id'] ?>" name="crawled" value="1" <?= $row['crawled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="crawledSwitch_<?= $row['id'] ?>"></label>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($row['date_added'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['date_crawled'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['emails_found']) ?></td>
                        <td><?= htmlspecialchars($row['urls_crawled']) ?></td>
                        <td>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="prioritySwitch_<?= $row['id'] ?>" form="updateForm_<?= $row['id'] ?>" name="priority" value="1" <?= ($row['priority'] ?? 0) == 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="prioritySwitch_<?= $row['id'] ?>"></label>
                            </div>
                        </td>
                        <td>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="donotSwitch_<?= $row['id'] ?>" form="updateForm_<?= $row['id'] ?>" name="donot" value="1" <?= ($row['donot'] ?? 0) == 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="donotSwitch_<?= $row['id'] ?>"></label>
                            </div>
                        </td>
                        <td class="text-end actions-col">
                            <button type="submit" form="updateForm_<?= $row['id'] ?>" class="btn btn-sm btn-primary me-1" title="Save Changes" onclick="return confirm('Save changes for domain ID <?= $row['id'] ?>?');"><i class="fas fa-save"></i></button>
                            <form method="post" class="d-inline-block" onsubmit="return confirm('Permanently delete domain ID <?= $row['id'] ?> and all associated emails?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Delete Domain"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" class="text-center text-muted py-4">No domains found for the current filter.</td> <!-- Adjusted colspan -->
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                    <?php
                    $prev_params = $_GET;
                    $prev_params['p'] = $current_page - 1;
                    unset($prev_params['msg_type'], $prev_params['msg_text']);
                    $prev_query_str = http_build_query($prev_params);
                    ?>
                    <a class="page-link" href="?<?= $prev_query_str ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($current_page == $i) ? 'active' : '' ?>">
                        <?php
                        $page_params = $_GET;
                        $page_params['p'] = $i;
                        unset($page_params['msg_type'], $page_params['msg_text']);
                        $page_query_str = http_build_query($page_params);
                        ?>
                        <a class="page-link" href="?<?= $page_query_str ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                    <?php
                    $next_params = $_GET;
                    $next_params['p'] = $current_page + 1;
                    unset($next_params['msg_type'], $next_params['msg_text']);
                    $next_query_str = http_build_query($next_params);
                    ?>
                    <a class="page-link" href="?<?= $next_query_str ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>