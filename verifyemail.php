<?php
// verifyemail.php (efficient pagination + global de-dup + inline edit/delete)
include __DIR__ . '/db.php';

// ========== Helpers ==========
function is_valid_email_format($email) { 
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
function has_space($email) {
    return preg_match('/\s/', $email) === 1;
}
function has_non_ascii($email) {
    return preg_match('/[^\x20-\x7E]/', $email) === 1;
}
// New checks
function has_encoded_space($email) {
    return stripos($email, '%20') !== false;
}
function has_bad_extension($email) {
    // Flag common file extensions that indicate bad scrape
    return preg_match('/\.(jpg|jpeg|png|gif|pdf)\b/i', $email) === 1;
}
function is_long_email($email, $limit = 30) {
    return strlen($email) > $limit;
}
function redirect_self(array $params = []) {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $query = array_merge($_GET, $params);
    header('Location: ' . $base . (empty($query) ? '' : ('?' . http_build_query($query))));
    exit;
}

// ========== Inputs / Pagination ==========
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage  = isset($_GET['perPage']) ? max(25, min(1000, (int)$_GET['perPage'])) : 200;
$search   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$showOnly = isset($_GET['showOnly']) ? (string)$_GET['showOnly'] : ''; // '', 'invalid', 'duplicates'

// ========== Actions (POST) ==========
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $newEmail = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
        $newName  = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE emails SET email = ?, name = ? WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ssi', $newEmail, $newName, $id);
                if ($stmt->execute()) {
                    $notice = "Email ID {$id} updated.";
                } else {
                    $error = "Update failed for ID {$id}: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare failed for update: " . $conn->error;
            }
        }
        redirect_self(['page' => $page, 'perPage' => $perPage, 'search' => $search, 'showOnly' => $showOnly, 'n' => $notice, 'e' => $error]);
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM emails WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $notice = "Email ID {$id} deleted.";
                } else {
                    $error = "Delete failed for ID {$id}: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = "Prepare failed for delete: " . $conn->error;
            }
        }
        redirect_self(['page' => $page, 'perPage' => $perPage, 'search' => $search, 'showOnly' => $showOnly, 'n' => $notice, 'e' => $error]);
    } elseif ($action === 'dedupe_all') {
        // Remove duplicates across the entire table, keep lowest id for each normalized (lower(trim)) email
        // NOTE: This keeps one row per normalized email (global), irrespective of domain_id
        $sql = "
            DELETE e FROM emails e
            JOIN (
                SELECT MIN(id) AS keep_id, LOWER(TRIM(email)) AS em
                FROM emails
                WHERE email IS NOT NULL AND email <> ''
                GROUP BY em
            ) k ON LOWER(TRIM(e.email)) = k.em AND e.id <> k.keep_id
        ";
        $ok = $conn->query($sql);
        if ($ok === true) {
            $affected = $conn->affected_rows;
            $notice = "Duplicate cleanup complete. Removed {$affected} rows (kept one per email).";
        } else {
            $error = "Duplicate cleanup failed: " . $conn->error;
        }
        redirect_self(['page' => 1, 'perPage' => $perPage, 'search' => $search, 'showOnly' => $showOnly, 'n' => $notice, 'e' => $error]);
    }
}

// Carry over notices via query to avoid re-posting on refresh
if (isset($_GET['n'])) $notice = (string)$_GET['n'];
if (isset($_GET['e'])) $error  = (string)$_GET['e'];

// ========== Build filters ==========
$where = [];
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where[] = "(email LIKE '%{$s}%' OR name LIKE '%{$s}%' OR CAST(domain_id AS CHAR) LIKE '%{$s}%')";
}
$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count total rows for pagination (based on current search only)
$countTotal = 0;
$resCnt = $conn->query("SELECT COUNT(*) AS c FROM emails {$whereSql}");
if ($resCnt && ($rowC = $resCnt->fetch_assoc())) {
    $countTotal = (int)$rowC['c'];
}

// ========== Fetch current page rows only ==========
$offset = ($page - 1) * $perPage;
$rows = [];
$sql = "SELECT id, domain_id, name, email FROM emails {$whereSql} ORDER BY id ASC LIMIT {$offset}, {$perPage}";
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}

// ========== Efficient duplicate detection (global) for current page only ==========
// Build list of normalized emails from page
$normList = [];
foreach ($rows as $r) {
    $norm = strtolower(trim((string)$r['email']));
    if ($norm !== '') $normList[$norm] = true;
}
$dupeCounts = [];
if (!empty($normList)) {
    $vals = array_map(function($x) use ($conn) { return "'" . $conn->real_escape_string($x) . "'"; }, array_keys($normList));
    $in = implode(',', $vals);
    $resD = $conn->query("SELECT LOWER(TRIM(email)) AS em, COUNT(*) AS c FROM emails WHERE LOWER(TRIM(email)) IN ({$in}) GROUP BY em");
    if ($resD) {
        while ($d = $resD->fetch_assoc()) {
            $dupeCounts[$d['em']] = (int)$d['c'];
        }
    }
}

// ========== Compute flags for current page (and apply 'showOnly' filter in memory) ==========
$filteredRows = [];
$invalidCount = 0;
$duplicateCount = 0;
foreach ($rows as $r) {
    $email = (string)$r['email'];
    $hasSpace = has_space($email);
    $hasNonAscii = has_non_ascii($email);
    $hasEncodedSpace = has_encoded_space($email);
    $hasExt = has_bad_extension($email);
    $isLong = is_long_email($email, 30);

    // Valid only if format ok AND none of the “bad” flags
    $valid = is_valid_email_format($email)
             && !$hasSpace
             && !$hasNonAscii
             && !$hasEncodedSpace
             && !$hasExt
             && !$isLong;

    $norm = strtolower(trim($email));
    $isDupe = ($norm !== '' && isset($dupeCounts[$norm]) && $dupeCounts[$norm] > 1);

    if (!$valid) $invalidCount++;
    if ($isDupe)  $duplicateCount++;

    $r['_has_space'] = $hasSpace;
    $r['_has_non_ascii'] = $hasNonAscii;
    $r['_has_encoded_space'] = $hasEncodedSpace;
    $r['_has_ext'] = $hasExt;
    $r['_is_long'] = $isLong;
    $r['_valid'] = $valid;
    $r['_is_dupe'] = $isDupe;

    if ($showOnly === 'invalid' && $valid) continue;
    if ($showOnly === 'duplicates' && !$isDupe) continue;

    $filteredRows[] = $r;
}

// ========== Pagination helpers ==========
$totalPages = max(1, (int)ceil($countTotal / $perPage));
function page_link($p, $perPage, $search, $showOnly) {
    $p = max(1, (int)$p);
    return '?' . http_build_query([
        'page' => $p,
        'perPage' => $perPage,
        'search' => $search,
        'showOnly' => $showOnly,
    ]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verify Emails (Efficient)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .row-duplicate { background-color: #f8d7da !important; } /* red-ish */
        .row-invalid   { background-color: #fff3cd !important; } /* yellow-ish */
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
        .small-input { width: 100%; max-width: 320px; }
        .small-name  { width: 100%; max-width: 220px; }
        .sticky-head th { position: sticky; top: 0; background: #fff; z-index: 2; }
    </style>
</head>
<body>


    <?php include 'nav.php'; ?>
    <div class="container">
    <?php if ($notice): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($notice, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="get" class="mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Find by email/name/domain_id..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Per Page</label>
                <select name="perPage" class="form-select">
                    <?php foreach ([100, 200, 300, 500, 1000] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage == $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Show Only</label>
                <select name="showOnly" class="form-select">
                    <option value="" <?= $showOnly === '' ? 'selected' : '' ?>>All</option>
                    <option value="invalid" <?= $showOnly === 'invalid' ? 'selected' : '' ?>>Invalid Format/Chars</option>
                    <option value="duplicates" <?= $showOnly === 'duplicates' ? 'selected' : '' ?>>Duplicates</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
                <a href="?perPage=<?= (int)$perPage ?>" class="btn btn-secondary">Clear</a>
            </div>
        </div>
    </form>

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex gap-3">
            <span>Total (search scope): <strong><?= number_format($countTotal) ?></strong></span>
            <span>Duplicates on this page: <strong class="text-danger"><?= (int)$duplicateCount ?></strong></span>
            <span>Invalid on this page: <strong class="text-warning"><?= (int)$invalidCount ?></strong></span>
        </div>
        <form method="post" onsubmit="return confirm('Remove all duplicate emails globally, keeping the lowest ID per email?');">
            <input type="hidden" name="action" value="dedupe_all">
            <button type="submit" class="btn btn-danger">Remove Duplicates (Keep One)</button>
        </form>
    </div>

    <!-- Pagination controls -->
    <nav aria-label="Page navigation" class="mb-2">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 1 ? '#' : page_link($page-1, $perPage, $search, $showOnly) ?>">Prev</a>
            </li>
            <li class="page-item disabled"><span class="page-link">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $totalPages ? '#' : page_link($page+1, $perPage, $search, $showOnly) ?>">Next</a>
            </li>
        </ul>
    </nav>

    <div class="table-responsive" style="max-height: 70vh;">
        <table class="table table-sm table-hover align-middle">
            <thead class="sticky-head">
            <tr>
                <th>ID</th>
                <th>Domain ID</th>
                <th>Name (edit)</th>
                <th>Email (edit)</th>
                <th>Flags</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody class="mono">
            <?php if (empty($filteredRows)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No emails found for the current selection.</td></tr>
            <?php else: ?>
                <?php foreach ($filteredRows as $r): ?>
                    <?php
                    $rowClass = $r['_is_dupe'] ? 'row-duplicate' : (!$r['_valid'] ? 'row-invalid' : '');
                    $flags = [];
                    if ($r['_is_dupe']) $flags[] = 'DUPLICATE';
                    if (!$r['_valid']) $flags[] = 'INVALID';
                    if ($r['_has_space']) $flags[] = 'SPACE';
                    if ($r['_has_encoded_space']) $flags[] = '%20';
                    if ($r['_has_ext']) $flags[] = 'EXT(jpg/png/pdf)';
                    if ($r['_is_long']) $flags[] = 'LONG>30';
                    if ($r['_has_non_ascii']) $flags[] = 'NON-ASCII';
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= (int)$r['domain_id'] ?></td>
                        <td>
                            <form method="post" class="d-flex gap-2">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="text" name="name" class="form-control form-control-sm small-name"
                                       value="<?= htmlspecialchars((string)$r['name'], ENT_QUOTES) ?>">
                        </td>
                        <td>
                                <input type="text" name="email" class="form-control form-control-sm small-input"
                                       value="<?= htmlspecialchars((string)$r['email'], ENT_QUOTES) ?>">
                        </td>
                        <td>
                            <?php if (!empty($flags)): ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars(implode(' | ', $flags), ENT_QUOTES) ?></span>
                            <?php else: ?>
                                <span class="text-muted">OK</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                                <button type="submit" class="btn btn-primary btn-sm">Update</button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete email ID <?= (int)$r['id'] ?>?');">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination controls bottom -->
    <nav aria-label="Page navigation" class="mt-2">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 1 ? '#' : page_link($page-1, $perPage, $search, $showOnly) ?>">Prev</a>
            </li>
            <li class="page-item disabled"><span class="page-link">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $totalPages ? '#' : page_link($page+1, $perPage, $search, $showOnly) ?>">Next</a>
            </li>
        </ul>
    </nav>

    <div class="small text-muted mt-2">
        Legend:
        <span class="ms-2">Red = duplicate emails.</span>
        <span class="ms-2">Yellow = invalid format or failing checks (SPACE, %20, EXT, LONG>20, NON-ASCII).</span>
    </div>
</div>
</body>
</html>