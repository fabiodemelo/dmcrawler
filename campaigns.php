<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();

include 'auth_check.php';
include 'db.php';

function redirect_self(array $params = []): void {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $query = array_merge($_GET, $params);
    unset($query['action'], $query['id'], $query['msg_type'], $query['msg_text']);
    header('Location: ' . $base . (empty($query) ? '' : ('?' . http_build_query($query))));
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $message = '<div class="alert alert-warning">Campaign name cannot be empty.</div>';
        } else {
            $check = $conn->prepare("SELECT id FROM campaigns WHERE name = ? LIMIT 1");
            $check->bind_param('s', $name);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $message = '<div class="alert alert-warning">Campaign "' . htmlspecialchars($name) . '" already exists.</div>';
            } else {
                $stmt = $conn->prepare("INSERT INTO campaigns (name, status) VALUES (?, 0)");
                $stmt->bind_param('s', $name);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Campaign "' . htmlspecialchars($name) . '" created.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
            $check->close();
        }
    } elseif ($action === 'activate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Deactivate all, then activate the selected one
            $conn->query("UPDATE campaigns SET status = 0");
            $stmt = $conn->prepare("UPDATE campaigns SET status = 1 WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $message = '<div class="alert alert-success">Campaign activated.</div>';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $check = $conn->prepare("SELECT id FROM campaigns WHERE name = ? AND id != ? LIMIT 1");
            $check->bind_param('si', $name, $id);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $message = '<div class="alert alert-warning">Name "' . htmlspecialchars($name) . '" already in use.</div>';
            } else {
                $stmt = $conn->prepare("UPDATE campaigns SET name = ? WHERE id = ? LIMIT 1");
                $stmt->bind_param('si', $name, $id);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Campaign updated.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
            $check->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Don't allow deleting the active campaign
            $chk = $conn->query("SELECT status FROM campaigns WHERE id = {$id} LIMIT 1");
            if ($chk && ($r = $chk->fetch_assoc()) && (int)$r['status'] === 1) {
                $message = '<div class="alert alert-danger">Cannot delete the active campaign. Activate a different one first.</div>';
            } else {
                $stmt = $conn->prepare("DELETE FROM campaigns WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Campaign deleted.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
        }
    }
    redirect_self(['msg_type' => (strpos($message, 'danger') !== false || strpos($message, 'warning') !== false ? 'danger' : 'success'), 'msg_text' => strip_tags($message)]);
}

if (isset($_GET['msg_type']) && isset($_GET['msg_text'])) {
    $message = '<div class="alert alert-' . htmlspecialchars($_GET['msg_type']) . '">' . htmlspecialchars($_GET['msg_text']) . '</div>';
}

// Fetch campaigns with stats
$campaigns = [];
$res = $conn->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM domains d WHERE d.campaign_id = c.id) AS domain_count,
           (SELECT COUNT(*) FROM emails e WHERE e.campaign_id = c.id) AS email_count
    FROM campaigns c
    ORDER BY c.status DESC, c.created_at DESC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $campaigns[] = $row;
    }
}

$activeCampaign = null;
foreach ($campaigns as $c) {
    if ((int)$c['status'] === 1) { $activeCampaign = $c; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns — Demelos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container-fluid px-4">
    <div class="page-header">
        <h1>Campaigns</h1>
        <p>Group domains and emails by campaign. The active campaign is used by getURLS and the crawler.</p>
    </div>

    <?= $message ?>

    <?php if ($activeCampaign): ?>
    <div class="alert alert-info d-flex align-items-center mb-4" style="background:rgba(79,70,229,0.1); border-color:var(--primary); color:var(--text-primary);">
        <i class="fas fa-bullseye me-3" style="font-size:1.5rem; color:var(--primary);"></i>
        <div>
            <strong>Active Campaign:</strong> <?= htmlspecialchars($activeCampaign['name']) ?>
            <span class="text-muted ms-2">(<?= number_format((int)$activeCampaign['domain_count']) ?> domains, <?= number_format((int)$activeCampaign['email_count']) ?> emails)</span>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning mb-4">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>No active campaign.</strong> Activate one below. getURLS and the crawler will not run without an active campaign.
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h3 class="mb-3">Create New Campaign</h3>
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add">
                <div class="col-md-8">
                    <label for="campaignName" class="form-label">Campaign Name</label>
                    <input type="text" class="form-control" id="campaignName" name="name" placeholder="e.g. Q1 Plumbers SF" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Create Campaign</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Campaign Name</th>
                    <th>Status</th>
                    <th>Domains</th>
                    <th>Emails</th>
                    <th>Created</th>
                    <th class="text-end actions-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <h4>No campaigns yet</h4>
                            <p>Create your first campaign above.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($campaigns as $row): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td>
                        <form id="updateForm_<?= $row['id'] ?>" method="post" class="d-inline-block w-100">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <input type="text" name="name" class="form-control form-control-sm form-control-sm-inline" value="<?= htmlspecialchars($row['name']) ?>" required>
                        </form>
                    </td>
                    <td>
                        <?php if ((int)$row['status'] === 1): ?>
                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((int)$row['domain_count']) ?></td>
                    <td><?= number_format((int)$row['email_count']) ?></td>
                    <td class="small"><?= date('Y-m-d', strtotime($row['created_at'])) ?></td>
                    <td class="text-end actions-col">
                        <?php if ((int)$row['status'] !== 1): ?>
                        <form method="post" class="d-inline-block">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success me-1" title="Set as Active" onclick="return confirm('Activate this campaign? This will deactivate the current one.');"><i class="fas fa-bullseye"></i></button>
                        </form>
                        <?php endif; ?>
                        <button type="submit" form="updateForm_<?= $row['id'] ?>" class="btn btn-sm btn-primary me-1" title="Save Name" onclick="return confirm('Save changes?');"><i class="fas fa-save"></i></button>
                        <?php if ((int)$row['status'] !== 1): ?>
                        <form method="post" class="d-inline-block" onsubmit="return confirm('Delete this campaign? Domains and emails will keep their data but lose the campaign link.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script data-cfasync="false" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
