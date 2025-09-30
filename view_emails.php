<?php
// version 3.5 - Enhanced
include 'auth_check.php';
include 'db.php';

// Helper for redirecting while preserving query parameters
function redirect_self(array $params = []): void {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $query = array_merge($_GET, $params);
    // Remove action/id/msg params from previous GET if present to avoid pollution
    unset($query['action'], $query['id'], $query['msg_type'], $query['msg_text'], $query['page']); // Added $query['page']
    header('Location: ' . $base . (empty($query) ? '' : ('?' . http_build_query($query))));
    exit;
}

// ========== Handle POST Actions (Update/Delete) ==========
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $conn; // Explicitly declare $conn as global here
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($id > 0) {
        if ($action === 'update') {
            $newName = trim($_POST['name'] ?? '');
            $newEmail = trim($_POST['email'] ?? '');

            // Remove Zero Width Space character and other invisible Unicode spaces
            // This prevents %E2%80%8B and similar issues
            $newName = preg_replace('/[\p{Z}\p{C}]/u', '', $newName);
            $newEmail = preg_replace('/[\p{Z}\p{C}]/u', '', $newEmail);
            $newEmail = str_replace(' ', '', $newEmail); // Ensure no regular spaces in email address

            // Basic validation
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $message = '<div class="alert alert-danger">Error: Invalid email format for ID ' . $id . '.</div>';
            } else {
                $stmt = $conn->prepare("UPDATE emails SET name = ?, email = ? WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ssi', $newName, $newEmail, $id);
                    if ($stmt->execute()) {
                        $message = '<div class="alert alert-success">Email ID ' . $id . ' updated successfully.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Error updating email ID ' . $id . ': ' . $stmt->error . '</div>';
                    }
                    $stmt->close();
                } else {
                    $message = '<div class="alert alert-danger">Error preparing update for ID ' . $id . ': ' . $conn->error . '</div>';
                }
            }
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM emails WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Email ID ' . $id . ' deleted successfully.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error deleting email ID ' . $id . ': ' . $stmt->error . '</div>';
                }
                $stmt->close();
            } else {
                $message = '<div class="alert alert-danger">Error preparing delete for ID ' . $id . ': ' . $conn->error . '</div>';
            }
        }
    } else {
        $message = '<div class="alert alert-danger">Error: Invalid ID provided for action.</div>';
    }
    // Redirect to clear POST data and display message, preserving GET params
    redirect_self(['msg_type' => (strpos($message, 'danger') !== false ? 'danger' : 'success'), 'msg_text' => strip_tags($message)]);
}

// Display messages from redirect
if (isset($_GET['msg_type']) && isset($_GET['msg_text'])) {
    $message = '<div class="alert alert-' . htmlspecialchars($_GET['msg_type']) . '">' . htmlspecialchars($_GET['msg_text']) . '</div>';
}

// ========== Pagination Setup ==========
$itemsPerPage = 100; // Default limit
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// ========== Search, Filter, and Order parameters ==========
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$orderBy = isset($_GET['orderBy']) ? $_GET['orderBy'] : 'created_at';
$orderDir = isset($_GET['orderDir']) && in_array(strtoupper($_GET['orderDir']), ['ASC', 'DESC']) ? $_GET['orderDir'] : 'DESC';

// New column filters
$filterDomain = isset($_GET['filter_domain']) ? $conn->real_escape_string($_GET['filter_domain']) : '';
$filterEmail  = isset($_GET['filter_email'])  ? $conn->real_escape_string($_GET['filter_email'])  : ''; // This filter is not used in the UI, but kept for consistency
$filterMa     = isset($_GET['filter_ma'])     ? $_GET['filter_ma'] : '';

// Whitelist orderBy columns
$valid_order_columns = ['id', 'd.domain', 'e.name', 'e.email', 'e.created_at', 'e.ma'];
if (!in_array($orderBy, $valid_order_columns)) {
    $orderBy = 'e.created_at'; // Default to a safe column
}

// Build WHERE clause
$baseWhereClauses = [];
if (!empty($search)) {
    $baseWhereClauses[] = "(e.email LIKE '%$search%' OR d.domain LIKE '%$search%' OR e.name LIKE '%$search%')";
}

switch ($filter) {
    case 'today': $baseWhereClauses[] = 'DATE(e.created_at) = CURDATE()'; break;
    case 'week': $baseWhereClauses[] = 'YEARWEEK(e.created_at, 1) = YEARWEEK(CURDATE(), 1)'; break;
    case 'month': $baseWhereClauses[] = 'MONTH(e.created_at) = MONTH(CURDATE()) AND YEAR(e.created_at) = YEAR(CURDATE())'; break;
    case 'year': $baseWhereClauses[] = 'YEAR(e.created_at) = YEAR(CURDATE())'; break;
}

// Column-specific filters
$columnWhereClauses = [];
if ($filterDomain !== '') {
    $columnWhereClauses[] = "d.domain = '$filterDomain'";
}
if ($filterEmail !== '') {
    $columnWhereClauses[] = "e.email = '$filterEmail'";
}
if ($filterMa !== '') {
    if ($filterMa === 'mautic') {
        $columnWhereClauses[] = "e.ma IS NOT NULL AND e.ma > 0"; // ma > 0 indicates synced with Mautic ID
    } elseif ($filterMa === 'scheduled') {
        $columnWhereClauses[] = "e.ma IS NULL OR e.ma = 0"; // ma is NULL or 0 indicates pending/not synced
    } elseif ($filterMa === 'failed') { // New filter option for failed attempts
        $columnWhereClauses[] = "e.ma < 0"; // Negative ma could indicate a failed attempt
    }
}

$whereClauses = array_merge($baseWhereClauses, $columnWhereClauses);
$whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
// For populating options without self-filtering, use only base filters
$whereSqlOptions = count($baseWhereClauses) > 0 ? 'WHERE ' . implode(' AND ', $baseWhereClauses) : '';

// --- Total Count Query for Pagination ---
$totalCountQuery = "SELECT COUNT(e.id) AS total FROM emails e JOIN domains d ON e.domain_id = d.id {$whereSql}";
$totalCountResult = $conn->query($totalCountQuery);
$totalEmails = 0;
if ($totalCountResult) {
    $totalEmails = (int)$totalCountResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalEmails / $itemsPerPage);

// Fetch emails from the database
$query = "SELECT e.id, d.domain, e.name, e.email, e.created_at, e.ma
          FROM emails e
          JOIN domains d ON e.domain_id = d.id
          {$whereSql}
          ORDER BY {$orderBy} {$orderDir}
          LIMIT {$itemsPerPage} OFFSET {$offset}"; // Added LIMIT and OFFSET
$result = $conn->query($query);

// Counts for MA statuses (respect current filters)
$countQuery = "SELECT
    SUM(CASE WHEN e.ma IS NOT NULL AND e.ma > 0 THEN 1 ELSE 0 END) AS count_mautic,
    SUM(CASE WHEN e.ma IS NULL OR e.ma = 0 THEN 1 ELSE 0 END) AS count_scheduled,
    SUM(CASE WHEN e.ma IS NOT NULL AND e.ma < 0 THEN 1 ELSE 0 END) AS count_failed
    FROM emails e
    JOIN domains d ON e.domain_id = d.id
    {$whereSql}";
$countRes = $conn->query($countQuery);
$counts = ['mautic' => 0, 'scheduled' => 0, 'failed' => 0];
if ($countRes && ($c = $countRes->fetch_assoc())) {
    $counts['mautic'] = (int)$c['count_mautic'];
    $counts['scheduled'] = (int)$c['count_scheduled'];
    $counts['failed'] = (int)$c['count_failed'];
}

// Distinct options for header filters (based on base filters like time/search)
$domainsRes = $conn->query("SELECT DISTINCT d.domain FROM emails e JOIN domains d ON e.domain_id = d.id {$whereSqlOptions} ORDER BY d.domain ASC");

// Helper function to generate sort links
function sort_link($column, $text, $currentOrderBy, $currentOrderDir) {
    $dir = ($column == $currentOrderBy && $currentOrderDir == 'ASC') ? 'DESC' : 'ASC';
    $icon = ($column == $currentOrderBy) ? ($currentOrderDir == 'ASC' ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>') : '';
    $params = $_GET;
    $params['orderBy'] = $column;
    $params['orderDir'] = $dir;
    // Remove temporary message params and page parameter to ensure sort link starts on page 1
    unset($params['msg_type'], $params['msg_text'], $params['page']);
    $queryStr = http_build_query($params);
    return "<a href='?{$queryStr}'>$text$icon</a>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>View Emails</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .table td, .table th { vertical-align: middle; }
        /* Adjusted inline inputs to work within a combined cell */
        .form-control-sm-inline {
            width: 100%;
            margin-bottom: 5px; /* Add some space between stacked inputs */
        }
        .form-control-sm-inline:last-of-type {
            margin-bottom: 0; /* No margin after the last one */
        }
        .actions-col { width: 150px; }
        /* NEW: Style for the domain filter dropdown */
        .domain-filter-dropdown {
            max-width: 200px; /* Set max-width for the dropdown */
            width: 100%; /* Ensure it takes full width up to max-width */
        }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid">
    <?= $message ?>
    <form method="get" class="mb-4 p-3 bg-light border rounded">
        <div class="row g-3">
            <div class="col-md-5">
                <label for="search" class="form-label visually-hidden">Search</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="Search by email, name or domain..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <label for="filter_time" class="form-label visually-hidden">Filter by Time</label>
                <select name="filter" id="filter_time" class="form-select">
                    <option value="all" <?= $filter == 'all' ? 'selected' : '' ?>>All Time</option>
                    <option value="today" <?= $filter == 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $filter == 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $filter == 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="year" <?= $filter == 'year' ? 'selected' : '' ?>>This Year</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Search</button>
            </div>
            <div class="col-md-2">
                <a href="view_emails.php" class="btn btn-secondary w-100"><i class="fas fa-eraser"></i> Clear</a>
            </div>

        </div>
        <!-- Preserve order params for filtering -->
        <input type="hidden" name="orderBy" value="<?= htmlspecialchars($orderBy) ?>">
        <input type="hidden" name="orderDir" value="<?= htmlspecialchars($orderDir) ?>">
        <!-- Preserve current column filters too -->
        <input type="hidden" name="filter_domain" value="<?= htmlspecialchars($filterDomain) ?>">
        <input type="hidden" name="filter_email" value="<?= htmlspecialchars($filterEmail) ?>">
        <input type="hidden" name="filter_ma" value="<?= htmlspecialchars($filterMa) ?>">
        <!-- The 'page' parameter is intentionally NOT preserved here, to reset to page 1 on new search/filter -->
    </form>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex gap-3 flex-wrap">
            <span class="btn btn-primary text-white d-flex flex-column align-items-center py-2">
                <small class="text-uppercase">Synced to Mautic</small>
                <span class="fw-bold"><?= number_format($counts['mautic']) ?></span>
            </span>
            <span class="btn btn-warning text-dark d-flex flex-column align-items-center py-2">
                <small class="text-uppercase">Pending Sync</small>
                <span class="fw-bold"><?= number_format($counts['scheduled']) ?></span>
            </span>
            <span class="btn btn-danger text-white d-flex flex-column align-items-center py-2">
                <small class="text-uppercase">Failed Sync</small>
                <span class="fw-bold"><?= number_format($counts['failed']) ?></span>
            </span>
        </div>
        <div>
            <a href="export.php?<?= http_build_query([
                    'filter' => $filter, 'search' => $search, 'filter_domain' => $filterDomain,
                    'filter_email' => $filterEmail, 'filter_ma' => $filterMa
            ]) ?>" class="btn btn-success"><i class="fas fa-download"></i> Export CSV</a>
            <a href="verifyemail.php" class="btn btn-success"><i class="fas fa-download"></i> Verify Emails</a>
        </div>

    </div>

    <!-- Column Filters and Table -->
    <form method="get" id="headerFiltersForm" class="mb-0">
        <?php foreach ($_GET as $key => $value):
            // Exclude column filters we're about to define, and also 'page'
            if (!in_array($key, ['filter_domain', 'filter_email', 'filter_ma', 'msg_type', 'msg_text', 'page'])): ?>
            <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
        <?php endif; endforeach; ?>

        <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover table-sm">
                <thead class="table-success"> <!-- Changed from table-dark to table-success for light green -->
                <tr>
                    <th><?= sort_link('id', 'ID', $orderBy, $orderDir) ?></th>
                    <th>
                        <select name="filter_domain" class="form-select form-select-sm domain-filter-dropdown" onchange="this.form.submit()"> <!-- Added domain-filter-dropdown class -->
                            <option value="">All Domains</option>
                            <?php if ($domainsRes): while ($rowD = $domainsRes->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($rowD['domain']) ?>" <?= $filterDomain === $rowD['domain'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rowD['domain']) ?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </th>
                    <th><?= sort_link('e.name', 'Name', $orderBy, $orderDir) ?></th>
                    <th><?= sort_link('e.email', 'Email', $orderBy, $orderDir) ?></th>
                    <th><?= sort_link('e.created_at', 'Date Found', $orderBy, $orderDir) ?></th>
                    <th>
                        <select name="filter_ma" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Mautic Status</option>
                            <option value="mautic" <?= $filterMa === 'mautic' ? 'selected' : '' ?>>Synced</option>
                            <option value="scheduled" <?= $filterMa === 'scheduled' ? 'selected' : '' ?>>Pending</option>
                            <option value="failed" <?= $filterMa === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </th>
                    <th class="text-end actions-col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>
                            <td><?= htmlspecialchars($row['domain']) ?></td>
                            <td>
                                <input type="text" form="updateForm_<?= $row['id'] ?>" name="name" class="form-control form-control-sm form-control-sm-inline" value="<?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES) ?>" placeholder="Name">
                            </td>
                            <td>
                                <input type="email" form="updateForm_<?= $row['id'] ?>" name="email" class="form-control form-control-sm form-control-sm-inline" value="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES) ?>" placeholder="Email">
                            </td>
                            <td>
                                <?= htmlspecialchars($row['created_at']) ?>
                            </td>
                            <td>
                                <?php
                                if ($row['ma'] !== null && (int)$row['ma'] > 0) {
                                    echo '<span class="badge bg-primary">Synced (ID: ' . htmlspecialchars($row['ma']) . ')</span>';
                                } elseif ($row['ma'] !== null && (int)$row['ma'] < 0) {
                                    echo '<span class="badge bg-danger">Failed</span>';
                                } else {
                                    echo '<span class="badge bg-secondary">Pending</span>'; 
                                }
                                ?>
                            </td>
                            <td class="text-end">
                                <form id="updateForm_<?= $row['id'] ?>" method="post" class="d-inline-flex gap-1">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary" title="Update" onclick="return confirm('Update Name and Email for ID <?= $row['id'] ?>?');"><i class="fas fa-save"></i></button>
                                </form>
                                <form method="post" class="d-inline-flex ms-1" onsubmit="return confirm('Permanently delete email ID <?= $row['id'] ?>?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No emails found for the current selection.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <!-- Pagination Links -->
    <nav aria-label="Page navigation" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php
            $linkParams = $_GET;
            unset($linkParams['msg_type'], $linkParams['msg_text']); // Clean temporary message params

            // Previous page link
            $prevPageParams = $linkParams;
            $prevPageParams['page'] = $currentPage - 1;
            ?>
            <li class="page-item <?= ($currentPage <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query($prevPageParams) ?>" aria-label="Previous">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>

            <?php if ($totalPages > 1): // Only show page numbers if there's more than one page ?>
                <?php
                $numLinksToShow = 5; // Total number of page links to show directly
                $half = floor($numLinksToShow / 2);
                $startPage = max(1, $currentPage - $half);
                $endPage = min($totalPages, $currentPage + $half);

                // Adjust start and end if window is too small at edges
                if ($endPage - $startPage + 1 < $numLinksToShow) {
                    if ($startPage == 1) {
                        $endPage = min($totalPages, $numLinksToShow);
                    } elseif ($endPage == $totalPages) {
                        $startPage = max(1, $totalPages - $numLinksToShow + 1);
                    }
                }

                // Show first page and ellipsis if necessary
                if ($startPage > 1) {
                    $pageParams = $linkParams;
                    $pageParams['page'] = 1;
                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($pageParams) . '">1</a></li>';
                    if ($startPage > 2) { // Show ellipsis if there's a gap bigger than 1 page
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }

                // Show pages in the calculated window
                for ($i = $startPage; $i <= $endPage; $i++):
                    $pageParams = $linkParams;
                    $pageParams['page'] = $i;
                    ?>
                    <li class="page-item <?= ($i == $currentPage) ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query($pageParams) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;

                // Show last page and ellipsis if necessary
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) { // Show ellipsis if there's a gap bigger than 1 page
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    $pageParams = $linkParams;
                    $pageParams['page'] = $totalPages;
                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query($pageParams) . '">' . $totalPages . '</a></li>';
                }
                ?>
            <?php endif; // End if ($totalPages > 1) ?>

            <?php
            // Next page link
            $nextPageParams = $linkParams;
            $nextPageParams['page'] = $currentPage + 1;
            ?>
            <li class="page-item <?= ($currentPage >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query($nextPageParams) ?>" aria-label="Next">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
        </ul>
        <div class="text-center text-muted">Showing <?= min($offset + 1, $totalEmails) ?>-<?= min($offset + $itemsPerPage, $totalEmails) ?> of <?= $totalEmails ?> emails.</div>
    </nav>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>