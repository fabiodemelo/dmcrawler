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

    // Search term (part of the same filter form)
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

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

    // ... existing code ...

    // Get total number of records for the current filter
    $count_sql = "SELECT COUNT(*) AS total FROM domains {$whereClause}";
    $count_result = $conn->query($count_sql);
    $total_records = 0;
    if ($count_result) {
        $total_records = (int)$count_result->fetch_assoc()['total'];
    } else {
        error_log("Error counting records for pagination: " . $conn->error . " SQL: " . $count_sql);
    }

    // ... existing code ...

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
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Domains</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table td, .table th { vertical-align: middle; }
        .form-control-sm-inline { width: 100%; }
        .domain-column { max-width: 300px; word-wrap: break-word; }
        .actions-col { width: 120px; }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>Manage Domains <small class="text-muted">(Total Domains Yet to Crawl: <?= number_format($totalDomainsYetToCrawl) ?>)</small></h1>

        <!-- Single filter form: status + search (no separate search script/form) -->
        <form method="get" class="d-flex align-items-center gap-2">
            <label for="statusFilter" class="me-2 mb-0">Show:</label>
            <select name="status" id="statusFilter" class="form-select form-select-sm" onchange="this.form.p.value=1; this.form.submit()">
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="crawled" <?= $filterStatus === 'crawled' ? 'selected' : '' ?>>Crawled</option>
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
            </select>

            <input type="text"
                   name="search"
                   class="form-control form-control-sm"
                   style="width:260px"
                   placeholder="Filter by domain..."
                   value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"
                   onkeydown="if(event.key==='Enter'){this.form.p.value=1; this.form.submit();}">

            <button type="submit" class="btn btn-outline-primary btn-sm" onclick="this.form.p.value=1;">Apply</button>
            <a class="btn btn-outline-secondary btn-sm" href="add_website.php?status=<?= htmlspecialchars($filterStatus, ENT_QUOTES) ?>">Clear</a>

            <input type="hidden" name="orderBy" value="<?= htmlspecialchars($orderBy) ?>">
            <input type="hidden" name="orderDir" value="<?= htmlspecialchars($orderDir) ?>">
            <input type="hidden" name="p" value="<?= htmlspecialchars($current_page) ?>">
        </form>
    </div>

    <?= $message ?>

    <!-- ... existing code ... -->