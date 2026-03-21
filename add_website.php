<?php
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

// AJAX toggle API — priority or donot
if (isset($_GET['ajax_toggle'])) {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $field = $_GET['field'] ?? '';
    if ($id <= 0 || !in_array($field, ['priority', 'donot'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid params']);
        exit;
    }
    // Read current value and flip it
    $res = $conn->query("SELECT `{$field}` FROM domains WHERE id = {$id} LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc())) {
        echo json_encode(['ok' => false, 'error' => 'Domain not found']);
        exit;
    }
    $newVal = (int)$row[$field] ? 0 : 1;
    $stmt = $conn->prepare("UPDATE domains SET `{$field}` = ? WHERE id = ? LIMIT 1");
    $stmt->bind_param('ii', $newVal, $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true, 'field' => $field, 'id' => $id, 'value' => $newVal]);
    exit;
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
    // Handle filtering by status
    $filterStatus = $_GET['status'] ?? 'pending'; // Default to 'pending'

    // Search term (part of the same filter form)
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

    // Pagination setup
    $records_per_page = 50;
    $current_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $offset = ($current_page - 1) * $records_per_page;

    // Sorting setup
    $orderBy = $_GET['orderBy'] ?? 'date_added';
    $orderDir = $_GET['orderDir'] ?? 'DESC';
    $validOrderColumns = ['id', 'domain', 'crawled', 'date_added', 'date_crawled', 'emails_found', 'urls_crawled', 'priority', 'donot'];
    if (!in_array($orderBy, $validOrderColumns)) {
        $orderBy = 'date_added';
    }
    if (!in_array(strtoupper($orderDir), ['ASC', 'DESC'])) {
        $orderDir = 'DESC';
    }
    $orderByClause = "`$orderBy` $orderDir";

    // Build a single WHERE clause that combines status + search
    $whereParts = [];

    if ($filterStatus === 'crawled') {
        $whereParts[] = 'crawled = 1';
    } elseif ($filterStatus === 'pending') {
        $whereParts[] = 'crawled = 0';
    } elseif ($filterStatus === 'all') {
        // no status condition
    }

    if ($search !== '') {
        $s = $conn->real_escape_string($search);
        $whereParts[] = "domain LIKE '%{$s}%'";
    }

    $whereClause = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // Get extra stats: Total domains yet to crawl
    $totalDomainsYetToCrawl = 0;
    $resStats = $conn->query("SELECT COUNT(*) AS total FROM domains WHERE crawled = 0 AND donot = 0");
    if ($resStats) {
        $totalDomainsYetToCrawl = (int)$resStats->fetch_assoc()['total'];
    }

    // Get total number of records for the current filter
    $count_sql = "SELECT COUNT(*) AS total FROM domains {$whereClause}";
    $count_result = $conn->query($count_sql);
    $total_records = 0;
    if ($count_result) {
        $total_records = (int)$count_result->fetch_assoc()['total'];
    } else {
        error_log("Error counting records for pagination: " . $conn->error . " SQL: " . $count_sql);
    }

    $total_pages = ceil($total_records / $records_per_page);

    // Fetch domains for display with filter, sorting, and pagination
    $domains = [];
    $sql = "SELECT id, domain, crawled, date_added, date_crawled, emails_found, urls_crawled, priority, donot
            FROM domains {$whereClause}
            ORDER BY {$orderByClause}
            LIMIT {$records_per_page} OFFSET {$offset}";
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
            <p>Manage crawling targets. <strong><?= number_format($totalDomainsYetToCrawl) ?></strong> domains pending crawl.</p>
        </div>
        <form method="get" class="d-flex align-items-center gap-2 mt-2">
            <select name="status" id="statusFilter" class="form-select" onchange="this.form.p.value=1; this.form.submit()" style="width:auto;">
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="crawled" <?= $filterStatus === 'crawled' ? 'selected' : '' ?>>Crawled</option>
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
            </select>
            <input type="text" name="search" class="form-control" style="width:260px" placeholder="Filter by domain..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" onkeydown="if(event.key==='Enter'){this.form.p.value=1; this.form.submit();}">
            <button type="submit" class="btn btn-primary" onclick="this.form.p.value=1;"><i class="fas fa-search"></i></button>
            <a class="btn btn-outline-secondary" href="add_website.php?status=<?= htmlspecialchars($filterStatus, ENT_QUOTES) ?>"><i class="fas fa-times"></i></a>
            <input type="hidden" name="orderBy" value="<?= htmlspecialchars($orderBy) ?>">
            <input type="hidden" name="orderDir" value="<?= htmlspecialchars($orderDir) ?>">
            <input type="hidden" name="p" value="<?= htmlspecialchars($current_page) ?>">
        </form>
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
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th class="domain-column">Domain</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th>Crawled</th>
                    <th>URLs</th>
                    <th>Emails</th>
                    <th>Priority</th>
                    <th>DoNot</th>
                    <th class="actions-col text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($domains)): ?>
                <tr><td colspan="10">
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
                        <span class="badge toggle-badge <?= $row['priority'] ? 'bg-warning text-dark' : 'bg-light text-muted' ?>"
                              id="priority-<?= $row['id'] ?>"
                              data-id="<?= $row['id'] ?>"
                              data-field="priority"
                              data-value="<?= $row['priority'] ?>"
                              onclick="toggleField(this)"
                              style="cursor:pointer;user-select:none;">
                            <?= $row['priority'] ? 'High' : 'Normal' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge toggle-badge <?= $row['donot'] ? 'bg-danger' : 'bg-light text-muted' ?>"
                              id="donot-<?= $row['id'] ?>"
                              data-id="<?= $row['id'] ?>"
                              data-field="donot"
                              data-value="<?= $row['donot'] ?>"
                              onclick="toggleField(this)"
                              style="cursor:pointer;user-select:none;">
                            <?= $row['donot'] ? 'Skip' : 'Crawl' ?>
                        </span>
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
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm justify-content-center mt-3">
            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=1&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>&orderBy=<?= urlencode($orderBy) ?>&orderDir=<?= urlencode($orderDir) ?>">First</a>
            </li>
            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=<?= $current_page - 1 ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>&orderBy=<?= urlencode($orderBy) ?>&orderDir=<?= urlencode($orderDir) ?>">Prev</a>
            </li>
            
            <?php
            $start = max(1, $current_page - 2);
            $end = min($total_pages, $current_page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                <a class="page-link" href="?p=<?= $i ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>&orderBy=<?= urlencode($orderBy) ?>&orderDir=<?= urlencode($orderDir) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>

            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=<?= $current_page + 1 ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>&orderBy=<?= urlencode($orderBy) ?>&orderDir=<?= urlencode($orderDir) ?>">Next</a>
            </li>
            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?p=<?= $total_pages ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>&orderBy=<?= urlencode($orderBy) ?>&orderDir=<?= urlencode($orderDir) ?>">Last</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleField(el) {
    var id = el.dataset.id;
    var field = el.dataset.field;
    var oldVal = parseInt(el.dataset.value);

    // Immediate visual feedback
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
                el.className = 'badge toggle-badge ' + (v ? 'bg-warning text-dark' : 'bg-light text-muted');
            } else if (field === 'donot') {
                el.textContent = v ? 'Skip' : 'Crawl';
                el.className = 'badge toggle-badge ' + (v ? 'bg-danger' : 'bg-light text-muted');
            }

            // Brief flash to confirm save
            el.style.transform = 'scale(1.2)';
            setTimeout(function() { el.style.transform = ''; }, 200);
        })
        .catch(function(e) {
            el.style.opacity = '1';
            el.style.pointerEvents = '';
            alert('Network error');
        });
}
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
</style>
</body>
</html>
<?php ob_end_flush(); ?>