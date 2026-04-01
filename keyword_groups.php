<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();

include 'auth_check.php';
include 'db.php';

// Auto-create tables
$conn->query("CREATE TABLE IF NOT EXISTS keyword_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS keyword_group_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    keyword_id INT NOT NULL,
    UNIQUE KEY uk_group_keyword (group_id, keyword_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS campaign_keyword_groups (
    campaign_id INT NOT NULL,
    group_id INT NOT NULL,
    PRIMARY KEY (campaign_id, group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajaxAction = $_GET['ajax'];

    if ($ajaxAction === 'add_to_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $keywordId = (int)($_POST['keyword_id'] ?? 0);
        if ($groupId > 0 && $keywordId > 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO keyword_group_items (group_id, keyword_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $groupId, $keywordId);
            $stmt->execute();
            $stmt->close();
            die(json_encode(['ok' => true]));
        }
        die(json_encode(['ok' => false, 'error' => 'Invalid params']));
    }

    if ($ajaxAction === 'remove_from_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $keywordId = (int)($_POST['keyword_id'] ?? 0);
        if ($groupId > 0 && $keywordId > 0) {
            $stmt = $conn->prepare("DELETE FROM keyword_group_items WHERE group_id = ? AND keyword_id = ?");
            $stmt->bind_param('ii', $groupId, $keywordId);
            $stmt->execute();
            $stmt->close();
            die(json_encode(['ok' => true]));
        }
        die(json_encode(['ok' => false, 'error' => 'Invalid params']));
    }

    die(json_encode(['ok' => false, 'error' => 'Unknown action']));
}

$message = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_group') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = $conn->prepare("INSERT IGNORE INTO keyword_groups (name) VALUES (?)");
            $stmt->bind_param('s', $name);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = '<div class="alert alert-success">Group "' . htmlspecialchars($name) . '" created.</div>';
            } else {
                $message = '<div class="alert alert-warning">Group already exists or error.</div>';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete_group') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn->query("DELETE FROM keyword_group_items WHERE group_id = {$id}");
            $conn->query("DELETE FROM campaign_keyword_groups WHERE group_id = {$id}");
            $stmt = $conn->prepare("DELETE FROM keyword_groups WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $message = '<div class="alert alert-success">Group deleted.</div>';
        }
    } elseif ($action === 'rename_group') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $stmt = $conn->prepare("UPDATE keyword_groups SET name = ? WHERE id = ? LIMIT 1");
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            $stmt->close();
            $message = '<div class="alert alert-success">Group renamed.</div>';
        }
    }

    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $msgType = (strpos($message, 'danger') !== false || strpos($message, 'warning') !== false) ? 'danger' : 'success';
    header('Location: ' . $base . '?msg_type=' . $msgType . '&msg_text=' . urlencode(strip_tags($message)));
    exit;
}

if (isset($_GET['msg_type']) && isset($_GET['msg_text'])) {
    $message = '<div class="alert alert-' . htmlspecialchars($_GET['msg_type']) . '">' . htmlspecialchars($_GET['msg_text']) . '</div>';
}

// Fetch data
$groups = [];
$res = $conn->query("SELECT * FROM keyword_groups ORDER BY name ASC");
if ($res) { while ($row = $res->fetch_assoc()) $groups[] = $row; }

$allKeywords = [];
$res = $conn->query("SELECT id, keyword, status FROM keywords ORDER BY keyword ASC");
if ($res) { while ($row = $res->fetch_assoc()) $allKeywords[] = $row; }

// Get assigned keyword IDs per group
$groupItems = [];
$res = $conn->query("SELECT group_id, keyword_id FROM keyword_group_items");
if ($res) { while ($row = $res->fetch_assoc()) $groupItems[(int)$row['group_id']][] = (int)$row['keyword_id']; }

// Keywords already in any group
$assignedKeywordIds = [];
foreach ($groupItems as $items) {
    foreach ($items as $kid) $assignedKeywordIds[$kid] = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keyword Groups — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Keyword Groups</h1>
        <p>Drag keywords into groups, then assign groups to campaigns.</p>
    </div>

    <?= $message ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add_group">
                <div class="col-md-8">
                    <label class="form-label">New Group Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Plumbing Terms" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Create Group</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <!-- Unassigned Keywords (left) -->
        <div class="col-lg-4">
            <div class="card" style="border-color:var(--primary);">
                <div class="card-body">
                    <h4 class="mb-3"><i class="fas fa-key me-2"></i>Available Keywords</h4>
                    <p class="text-muted small mb-2">Drag keywords to a group on the right.</p>
                    <div id="unassigned-pool" class="keyword-pool" style="min-height:100px;">
                        <?php foreach ($allKeywords as $kw): ?>
                        <?php if (!isset($assignedKeywordIds[(int)$kw['id']])): ?>
                        <div class="keyword-chip" draggable="true" data-keyword-id="<?= $kw['id'] ?>">
                            <?= htmlspecialchars($kw['keyword']) ?>
                            <?php if (!(int)$kw['status']): ?><span class="text-muted">(inactive)</span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Groups (right) -->
        <div class="col-lg-8">
            <?php if (empty($groups)): ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <h4>No groups yet</h4>
                        <p>Create a keyword group above to get started.</p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($groups as $group): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <form method="post" class="d-flex align-items-center gap-2 flex-grow-1">
                            <input type="hidden" name="action" value="rename_group">
                            <input type="hidden" name="id" value="<?= $group['id'] ?>">
                            <h4 class="mb-0 flex-grow-1">
                                <input type="text" name="name" class="form-control form-control-sm form-control-sm-inline" value="<?= htmlspecialchars($group['name']) ?>" style="font-weight:700; font-size:1rem;">
                            </h4>
                            <button type="submit" class="btn btn-sm btn-primary" title="Rename"><i class="fas fa-save"></i></button>
                        </form>
                        <form method="post" class="ms-2" onsubmit="return confirm('Delete this group?');">
                            <input type="hidden" name="action" value="delete_group">
                            <input type="hidden" name="id" value="<?= $group['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Group"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                    <div class="keyword-pool group-drop-zone" data-group-id="<?= $group['id'] ?>" style="min-height:50px; border:2px dashed rgba(255,255,255,0.1); border-radius:8px; padding:8px;">
                        <?php
                        $itemIds = $groupItems[(int)$group['id']] ?? [];
                        foreach ($itemIds as $kid):
                            $kwName = '';
                            foreach ($allKeywords as $kw) { if ((int)$kw['id'] === $kid) { $kwName = $kw['keyword']; break; } }
                            if ($kwName === '') continue;
                        ?>
                        <div class="keyword-chip in-group" draggable="true" data-keyword-id="<?= $kid ?>" data-group-id="<?= $group['id'] ?>">
                            <?= htmlspecialchars($kwName) ?>
                            <span class="remove-chip" data-keyword-id="<?= $kid ?>" data-group-id="<?= $group['id'] ?>">&times;</span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($itemIds)): ?>
                        <span class="text-muted small drop-hint">Drop keywords here</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script data-cfasync="false">
// Drag and drop
var draggedEl = null;

document.addEventListener('dragstart', function(e) {
    var chip = e.target.closest('.keyword-chip');
    if (!chip) return;
    draggedEl = chip;
    chip.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
});

document.addEventListener('dragend', function(e) {
    if (draggedEl) draggedEl.style.opacity = '1';
    draggedEl = null;
    document.querySelectorAll('.group-drop-zone').forEach(function(z) { z.classList.remove('drag-over'); });
});

document.addEventListener('dragover', function(e) {
    var zone = e.target.closest('.group-drop-zone, #unassigned-pool');
    if (zone) { e.preventDefault(); zone.classList.add('drag-over'); }
});

document.addEventListener('dragleave', function(e) {
    var zone = e.target.closest('.group-drop-zone, #unassigned-pool');
    if (zone) zone.classList.remove('drag-over');
});

document.addEventListener('drop', function(e) {
    e.preventDefault();
    var zone = e.target.closest('.group-drop-zone, #unassigned-pool');
    if (!zone || !draggedEl) return;
    zone.classList.remove('drag-over');

    var keywordId = draggedEl.dataset.keywordId;
    var oldGroupId = draggedEl.dataset.groupId || null;
    var newGroupId = zone.dataset.groupId || null;

    if (oldGroupId === newGroupId) return;

    // Remove from old group
    if (oldGroupId) {
        postAjax('remove_from_group', { group_id: oldGroupId, keyword_id: keywordId });
    }

    // Add to new group
    if (newGroupId) {
        postAjax('add_to_group', { group_id: newGroupId, keyword_id: keywordId }, function() {
            location.reload();
        });
    } else {
        // Moved back to unassigned
        location.reload();
    }
});

// Remove chip on X click
document.addEventListener('click', function(e) {
    var rm = e.target.closest('.remove-chip');
    if (!rm) return;
    postAjax('remove_from_group', { group_id: rm.dataset.groupId, keyword_id: rm.dataset.keywordId }, function() {
        location.reload();
    });
});

function postAjax(action, data, cb) {
    var form = new FormData();
    for (var k in data) form.append(k, data[k]);
    fetch('keyword_groups.php?ajax=' + action, { method: 'POST', body: form })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (cb) cb(d); })
        .catch(function(e) { console.error(e); });
}
</script>
<style>
.keyword-pool { display:flex; flex-wrap:wrap; gap:6px; }
.keyword-chip {
    background:#1e293b; border:1px solid #334155; border-radius:6px;
    padding:4px 10px; font-size:0.8rem; cursor:grab; user-select:none;
    color:#e2e8f0; transition: all 0.15s;
}
.keyword-chip:hover { border-color:var(--primary); }
.keyword-chip.in-group { background:#172554; border-color:#1e40af; }
.remove-chip { cursor:pointer; margin-left:6px; color:#f87171; font-weight:700; }
.remove-chip:hover { color:#ef4444; }
.drag-over { border-color:var(--primary) !important; background:rgba(79,70,229,0.05); }
.drop-hint { font-style:italic; }
</style>
</body>
</html>
<?php ob_end_flush(); ?>
