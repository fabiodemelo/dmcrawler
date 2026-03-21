<?php
error_reporting(E_ALL); // Report all PHP errors
ini_set('display_errors', '1'); // Display errors directly in the output

ob_start(); // Start output buffering at the very beginning of the script

// engines.php - Manage Search Engines (Add, View, Edit, Delete, Toggle Status)
include 'auth_check.php'; // For authentication
include 'db.php';         // For database connection ($conn)

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

// Handle POST actions (Add/Update/Delete Engine)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $newName = trim($_POST['name'] ?? '');
        if (!empty($newName)) {
            // Check for existing engine
            $stmt_check = $conn->prepare("SELECT id FROM engines WHERE name = ? LIMIT 1");
            if ($stmt_check) {
                $stmt_check->bind_param('s', $newName);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $message = '<div class="alert alert-warning">Engine name already exists.</div>';
                }
                $stmt_check->close();
            } else {
                $message = '<div class="alert alert-danger">Error preparing engine check: ' . $conn->error . '</div>';
            }

            // Only proceed with insert if no existing engine was found and no error occurred during check
            if (empty($message)) {
                $stmt_insert = $conn->prepare("INSERT INTO engines (name, status) VALUES (?, 1)"); // Default status to active (1)
                if ($stmt_insert) {
                    $stmt_insert->bind_param('s', $newName);
                    if ($stmt_insert->execute()) {
                        $message = '<div class="alert alert-success">Engine "' . htmlspecialchars($newName) . '" added successfully.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Error adding engine: ' . $stmt_insert->error . '</div>';
                    }
                    $stmt_insert->close();
                } else {
                    $message = '<div class="alert alert-danger">Error preparing add statement: ' . $conn->error . '</div>';
                }
            }
        } else {
            $message = '<div class="alert alert-warning">Engine name cannot be empty.</div>';
        }
    } elseif ($action === 'update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $updatedName = trim($_POST['name'] ?? '');
        $status = isset($_POST['status']) && $_POST['status'] === '1' ? 1 : 0;

        if ($id > 0 && !empty($updatedName)) {
            // Check if the engine name already exists for another ID
            $stmt_check = $conn->prepare("SELECT id FROM engines WHERE name = ? AND id != ? LIMIT 1");
            if ($stmt_check) {
                $stmt_check->bind_param('si', $updatedName, $id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $message = '<div class="alert alert-warning">Engine name "' . htmlspecialchars($updatedName) . '" already exists for another entry.</div>';
                }
                $stmt_check->close();
            } else {
                $message = '<div class="alert alert-danger">Error preparing engine check for update: ' . $conn->error . '</div>';
            }

            // Only proceed with update if no existing engine with different ID was found and no error occurred during check
            if (empty($message)) {
                $stmt_update = $conn->prepare("UPDATE engines SET name = ?, status = ? WHERE id = ? LIMIT 1");
                if ($stmt_update) {
                    $stmt_update->bind_param('sii', $updatedName, $status, $id);
                    if ($stmt_update->execute()) {
                        $message = '<div class="alert alert-success">Engine ID ' . $id . ' updated successfully.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Error updating engine ID ' . $id . ': ' . $stmt_update->error . '</div>';
                    }
                    $stmt_update->close();
                } else {
                    $message = '<div class="alert alert-danger">Error preparing update statement for ID ' . $id . ': ' . $conn->error . '</div>';
                }
            }
        } else {
            $message = '<div class="alert alert-danger">Error: Invalid ID or engine name provided for update.</div>';
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt_delete = $conn->prepare("DELETE FROM engines WHERE id = ? LIMIT 1");
            if ($stmt_delete) {
                $stmt_delete->bind_param('i', $id);
                if ($stmt_delete->execute()) {
                    $message = '<div class="alert alert-success">Engine ID ' . $id . ' deleted successfully.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error deleting engine ID ' . $id . ': ' . $stmt_delete->error . '</div>';
                }
                $stmt_delete->close();
            } else {
                $message = '<div class="alert alert-danger">Error preparing delete statement for ID ' . $id . ': ' . $conn->error . '</div>';
            }
        } else {
            $message = '<div class="alert alert-danger">Error: Invalid ID provided for deletion.</div>';
        }
    }
    redirect_self(['msg_type' => (strpos($message, 'danger') !== false || strpos($message, 'warning') !== false ? 'danger' : 'success'), 'msg_text' => strip_tags($message)]);
}

// Display messages from redirect
if (isset($_GET['msg_type']) && isset($_GET['msg_text'])) {
    $message = '<div class="alert alert-' . htmlspecialchars($_GET['msg_type']) . '">' . htmlspecialchars($_GET['msg_text']) . '</div>';
}

// Function to generate sorting links
function sort_link($column, $text, $currentOrderBy, $currentOrderDir) {
    $dir = ($column == $currentOrderBy && $currentOrderDir == 'ASC') ? 'DESC' : 'ASC';
    $icon = ($column == $currentOrderBy) ? ($currentOrderDir == 'ASC' ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>') : '';
    $params = $_GET;
    $params['orderBy'] = $column;
    $params['orderDir'] = $dir;
    // Remove temporary message params
    unset($params['msg_type'], $params['msg_text']);
    $queryStr = http_build_query($params);
    return "<a href='?{$queryStr}'>$text$icon</a>";
}

$orderBy = $_GET['orderBy'] ?? 'id';
$orderDir = $_GET['orderDir'] ?? 'DESC';
$valid_order_columns = ['id', 'name', 'status'];
if (!in_array($orderBy, $valid_order_columns)) {
    $orderBy = 'id';
}
if (!in_array(strtoupper($orderDir), ['ASC', 'DESC'])) {
    $orderDir = 'DESC';
}

// Fetch engines for display with sorting
$engines = [];
$result = $conn->query("SELECT id, name, status FROM engines ORDER BY {$orderBy} {$orderDir}");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $engines[] = $row;
    }
    $result->free();
} else {
    $message = '<div class="alert alert-danger">Error fetching engines: ' . $conn->error . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engines — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Search Engines</h1>
        <p>Manage SerpAPI search engines for domain discovery.</p>
    </div>
    <?= $message ?>

    <div class="card mb-4">
        <div class="card-body">
            <h3 class="mb-3">Add New Engine</h3>
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add">
                <div class="col-md-8">
                    <label for="newEngine" class="form-label">Engine Name (SerpAPI)</label>
                    <input type="text" class="form-control" id="newEngine" name="name" placeholder="e.g., google, google_maps, bing, yahoo" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Add Engine</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card">
        <table class="table">
            <thead>
            <tr>
                <th><?= sort_link('id', 'ID', $orderBy, $orderDir) ?></th>
                <th class="engine-column"><?= sort_link('name', 'Engine Name', $orderBy, $orderDir) ?></th>
                <th><?= sort_link('status', 'Status', $orderBy, $orderDir) ?></th>
                <th class="text-end actions-col">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($engines)): ?>
                <?php foreach ($engines as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td class="engine-column">
                            <form id="updateForm_<?= $row['id'] ?>" method="post" class="d-inline-block w-100">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                <input type="text" name="name" class="form-control form-control-sm-inline" value="<?= htmlspecialchars($row['name']) ?>" required>
                            </form>
                        </td>
                        <td>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="statusSwitch_<?= $row['id'] ?>" form="updateForm_<?= $row['id'] ?>" name="status" value="1" <?= $row['status'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="statusSwitch_<?= $row['id'] ?>"></label>
                            </div>
                        </td>
                        <td class="text-end actions-col">
                            <button type="submit" form="updateForm_<?= $row['id'] ?>" class="btn btn-sm btn-primary me-1" title="Save Changes" onclick="return confirm('Save changes for engine ID <?= $row['id'] ?>?');"><i class="fas fa-save"></i></button>
                            <form method="post" class="d-inline-block" onsubmit="return confirm('Permanently delete engine ID <?= $row['id'] ?>?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Delete Engine"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h4>No engines yet</h4>
                            <p>Add a search engine above to start discovering domains.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
ob_end_flush(); // Flush the output buffer at the end of the script
?>
