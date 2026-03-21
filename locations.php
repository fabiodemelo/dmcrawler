<?php
error_reporting(E_ALL); // Report all PHP errors
ini_set('display_errors', '1'); // Display errors directly in the output

ob_start(); // Start output buffering at the very beginning of the script

// locations.php - Manage Locations (Add, View, Edit, Delete, Toggle Status)
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

// Handle POST actions (Add/Update/Delete Location)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $newLocation = trim($_POST['name'] ?? '');
        if (!empty($newLocation)) {
            // Check for existing location
            $existing_location_id = null;
            $stmt_check = $conn->prepare("SELECT id FROM locations WHERE name = ? LIMIT 1");
            if ($stmt_check) {
                $stmt_check->bind_param('s', $newLocation);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $message = '<div class="alert alert-warning">Location already exists.</div>';
                }
                $stmt_check->close(); // Close the SELECT statement here
            } else {
                $message = '<div class="alert alert-danger">Error preparing location check: ' . $conn->error . '</div>';
            }

            // Only proceed with insert if no existing location was found and no error occurred during check
            if (empty($message)) {
                $stmt_insert = $conn->prepare("INSERT INTO locations (name, status) VALUES (?, 1)"); // Default status to active (1)
                if ($stmt_insert) {
                    $stmt_insert->bind_param('s', $newLocation);
                    if ($stmt_insert->execute()) {
                        $message = '<div class="alert alert-success">Location "' . htmlspecialchars($newLocation) . '" added successfully.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Error adding location: ' . $stmt_insert->error . '</div>';
                    }
                    $stmt_insert->close(); // Close the INSERT statement here
                } else {
                    $message = '<div class="alert alert-danger">Error preparing add statement: ' . $conn->error . '</div>';
                }
            }
        } else {
            $message = '<div class="alert alert-warning">Location cannot be empty.</div>';
        }
    } elseif ($action === 'update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $updatedLocation = trim($_POST['name'] ?? '');
        $status = isset($_POST['status']) && $_POST['status'] === '1' ? 1 : 0;

        if ($id > 0 && !empty($updatedLocation)) {
            // Check if the location already exists for another ID
            $existing_other_id = null;
            $stmt_check = $conn->prepare("SELECT id FROM locations WHERE name = ? AND id != ? LIMIT 1");
            if ($stmt_check) {
                $stmt_check->bind_param('si', $updatedLocation, $id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $message = '<div class="alert alert-warning">Location "' . htmlspecialchars($updatedLocation) . '" already exists for another entry.</div>';
                }
                $stmt_check->close(); // Close the SELECT statement here
            } else {
                $message = '<div class="alert alert-danger">Error preparing location check for update: ' . $conn->error . '</div>';
            }

            // Only proceed with update if no existing location with different ID was found and no error occurred during check
            if (empty($message)) {
                $stmt_update = $conn->prepare("UPDATE locations SET name = ?, status = ? WHERE id = ? LIMIT 1");
                if ($stmt_update) {
                    $stmt_update->bind_param('sii', $updatedLocation, $status, $id);
                    if ($stmt_update->execute()) {
                        $message = '<div class="alert alert-success">Location ID ' . $id . ' updated successfully.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Error updating location ID ' . $id . ': ' . $stmt_update->error . '</div>';
                    }
                    $stmt_update->close(); // Close the UPDATE statement here
                } else {
                    $message = '<div class="alert alert-danger">Error preparing update statement for ID ' . $id . ': ' . $conn->error . '</div>';
                }
            }
        } else {
            $message = '<div class="alert alert-danger">Error: Invalid ID or location provided for update.</div>';
        }
    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt_delete = $conn->prepare("DELETE FROM locations WHERE id = ? LIMIT 1");
            if ($stmt_delete) {
                $stmt_delete->bind_param('i', $id);
                if ($stmt_delete->execute()) {
                    $message = '<div class="alert alert-success">Location ID ' . $id . ' deleted successfully.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error deleting location ID ' . $id . ': ' . $stmt_delete->error . '</div>';
                }
                $stmt_delete->close(); // Close the DELETE statement here
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
$valid_order_columns = ['id', 'name', 'status']; // Changed 'keyword' to 'name'
if (!in_array($orderBy, $valid_order_columns)) {
    $orderBy = 'id';
}
if (!in_array(strtoupper($orderDir), ['ASC', 'DESC'])) {
    $orderDir = 'DESC';
}

// Fetch locations for display with sorting
$locations = [];
$result = $conn->query("SELECT id, name, status FROM locations ORDER BY {$orderBy} {$orderDir}"); // Changed 'keywords' to 'locations' and 'keyword' to 'name'
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
    $result->free();
} else {
    $message = '<div class="alert alert-danger">Error fetching locations: ' . $conn->error . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locations — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Locations</h1>
        <p>Manage geographic locations for targeted search queries.</p>
    </div>
    <?= $message ?>

    <div class="card mb-4">
        <div class="card-body">
            <h3 class="mb-3">Add New Location</h3>
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add">
                <div class="col-md-8">
                    <label for="newLocation" class="form-label">Location</label>
                    <input type="text" class="form-control" id="newLocation" name="name" placeholder="e.g., California" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Add Location</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card">
        <table class="table">
            <thead>
                <tr>
                    <th><?= sort_link('id', 'ID', $orderBy, $orderDir) ?></th>
                    <th class="location-column"><?= sort_link('name', 'Location', $orderBy, $orderDir) ?></th> <!-- Changed 'keyword' to 'name' and 'Keyword' to 'Location' -->
                    <th><?= sort_link('status', 'Status', $orderBy, $orderDir) ?></th>
                    <th class="text-end actions-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($locations)): ?> <!-- Changed $keywords to $locations -->
                    <?php foreach ($locations as $row): ?> <!-- Changed $keywords to $locations -->
                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>
                            <td class="location-column"> <!-- Changed class -->
                                <form id="updateForm_<?= $row['id'] ?>" method="post" class="d-inline-block w-100">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                    <input type="text" name="name" class="form-control form-control-sm-inline" value="<?= htmlspecialchars($row['name']) ?>" required> <!-- Changed name and value from keyword to name -->
                                </form>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="statusSwitch_<?= $row['id'] ?>" form="updateForm_<?= $row['id'] ?>" name="status" value="1" <?= $row['status'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="statusSwitch_<?= $row['id'] ?>"></label>
                                </div>
                            </td>
                            <td class="text-end actions-col">
                                <button type="submit" form="updateForm_<?= $row['id'] ?>" class="btn btn-sm btn-primary me-1" title="Save Changes" onclick="return confirm('Save changes for location ID <?= $row['id'] ?>?');"><i class="fas fa-save"></i></button> <!-- Changed keyword to location -->
                                <form method="post" class="d-inline-block" onsubmit="return confirm('Permanently delete location ID <?= $row['id'] ?>?');"> <!-- Changed keyword to location -->
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Location"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">
                        <div class="empty-state">
                            <i class="fas fa-map-marker-alt"></i>
                            <h4>No locations yet</h4>
                            <p>Add your first location above to target specific areas.</p>
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
