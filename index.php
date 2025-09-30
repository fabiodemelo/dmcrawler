<?php
// index.php
include 'auth_check.php'; // Include authentication check
include 'db.php'; // Include database connection
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-header {
            font-weight: bold;
        }
        .status-badge {
            font-size: 0.85em;
            padding: 0.3em 0.6em;
            border-radius: 0.25rem;
            vertical-align: middle;
        }
        .status-running {
            background-color: #28a745; /* Green */
            color: white;
        }
        .status-idle {
            background-color: #6c757d; /* Gray */
            color: white;
        }
        .text-info-dark {
            color: #0d6efd; /* A darker shade of Bootstrap's info color */
        }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="container mt-4">
    <h1>Dashboard</h1>

    <?php
    if (isset($_GET['started'])) {
        $script_name = htmlspecialchars($_GET['started']);
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
        echo '<strong>Success!</strong> ' . ucfirst(str_replace('_', ' ', $script_name)) . ' process started in the background.';
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }

    // Fetch dashboard statistics
    $totalDomains = 0;
    $crawledDomains = 0;
    $totalUrlsCrawled = 0;
    $totalEmailsFound = 0;
    $avgEmailsPerUrl = 0;
    $avgEmailsPerDomain = 0;
    $emailsPendingMautic = 0;

    if ($conn) {
        $res = $conn->query("SELECT COUNT(*) AS total FROM domains");
        if ($res) $totalDomains = $res->fetch_assoc()['total'];

        $res = $conn->query("SELECT COUNT(*) AS crawled FROM domains WHERE crawled = 1");
        if ($res) $crawledDomains = $res->fetch_assoc()['crawled'];

        $res = $conn->query("SELECT SUM(urls_crawled) AS total_urls FROM domains WHERE crawled = 1");
        if ($res && ($row = $res->fetch_assoc())) $totalUrlsCrawled = (int)$row['total_urls'];

        $res = $conn->query("SELECT COUNT(*) AS total_emails FROM emails");
        if ($res && ($row = $res->fetch_assoc())) $totalEmailsFound = (int)$row['total_emails'];

        $res = $conn->query("SELECT COUNT(*) AS total_pending FROM emails WHERE ma IS NULL OR ma = 0");
        if ($res && ($row = $res->fetch_assoc())) $emailsPendingMautic = (int)$row['total_pending'];

        if ($totalUrlsCrawled > 0) {
            $avgEmailsPerUrl = $totalEmailsFound / $totalUrlsCrawled;
        }
        if ($crawledDomains > 0) {
            $avgEmailsPerDomain = $totalEmailsFound / $crawledDomains;
        }
    }
    ?>

    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-white bg-primary mb-3">
                <div class="card-header">Total Domains</div>
                <div class="card-body">
                    <h5 class="card-title"><?= number_format($totalDomains) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-success mb-3">
                <div class="card-header">Crawled Domains</div>
                <div class="card-body">
                    <h5 class="card-title"><?= number_format($crawledDomains) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-info mb-3">
                <div class="card-header">Total URLs Crawled</div>
                <div class="card-body">
                    <h5 class="card-title"><?= number_format($totalUrlsCrawled) ?></h5>  
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-warning mb-3">
                <div class="card-header">Total Emails Found</div>
                <div class="card-body">
                    <h5 class="card-title"><?= number_format($totalEmailsFound) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-secondary mb-3">
                <div class="card-header">Avg per URL</div>
                <div class="card-body">
                    <h5 class="card-title"><?= sprintf("%.2f", $avgEmailsPerUrl) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-secondary mb-3">
                <div class="card-header">Avg per Domain</div>
                <div class="card-body">
                    <h5 class="card-title"><?= sprintf("%.2f", $avgEmailsPerDomain) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-dark bg-light mb-3">
                <div class="card-header">Emails for Mautic</div>
                <div class="card-body">
                    <h5 class="card-title"><?= number_format($emailsPendingMautic) ?></h5>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    Automated Tasks
                </div>
                <div class="card-body">
                    <p class="card-text">Trigger background processes for data acquisition and synchronization.</p>
                    <div class="d-grid gap-2">
                        <a href="run_crawler.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-spider"></i> Run Crawler (Domains)
                        </a>
                        <a href="run_get_urls.php" class="btn btn-info btn-lg">
                            <i class="fas fa-link"></i> Get URLs
                        </a>
                        <a href="run_get_emails.php" class="btn btn-warning btn-lg">
                            <i class="fas fa-envelope"></i> Get Emails
                        </a>
                        <a href="run_send_mautic.php" class="btn btn-success btn-lg">
                            <i class="fas fa-share-square"></i> Send Emails to Mautic
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    Process Status
                </div>
                <div class="card-body">
                    <p class="card-text">Current status of long-running background tasks. Hover for start time.</p>
                    <ul class="list-group" id="process-status-list">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Crawler (Domains)
                            <span class="status-badge bg-secondary" id="status-crawler">Loading...</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Get URLs
                            <span class="status-badge bg-secondary" id="status-geturls">Loading...</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Get Emails
                            <span class="status-badge bg-secondary" id="status-getemails">Loading...</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Send Emails to Mautic
                            <span class="status-badge bg-secondary" id="status-addtomautic">Loading...</span>
                        </li>
                    </ul>
                    <p class="mt-3 text-muted small">Status updates every 5 seconds.</p>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateProcessStatus() {
        fetch('status_api.php')
            .then(response => response.json())
            .then(data => {
                const statusMap = {
                    'status-crawler': 'crawler',
                    'status-geturls': 'geturls',
                    'status-getemails': 'getemails',
                    'status-addtomautic': 'addtomautic'
                };

                for (const elementId in statusMap) {
                    const scriptKey = statusMap[elementId];
                    const statusElement = document.getElementById(elementId);
                    if (statusElement && data[scriptKey]) {
                        if (data[scriptKey].running) {
                            statusElement.textContent = 'Running';
                            statusElement.classList.remove('bg-secondary');
                            statusElement.classList.add('bg-success'); // Green for running
                            if (data[scriptKey].started_at) {
                                statusElement.setAttribute('title', 'Started at: ' + data[scriptKey].started_at);
                            }
                        } else {
                            statusElement.textContent = 'Idle';
                            statusElement.classList.remove('bg-success');
                            statusElement.classList.add('bg-secondary'); // Gray for idle
                            statusElement.removeAttribute('title');
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching process status:', error);
                const statusList = document.getElementById('process-status-list');
                if (statusList && !document.getElementById('status-error-alert')) {
                    const errorAlert = document.createElement('div');
                    errorAlert.id = 'status-error-alert';
                    errorAlert.className = 'alert alert-warning mt-3';
                    errorAlert.textContent = 'Could not fetch process statuses. Check server logs.';
                    statusList.parentNode.insertBefore(errorAlert, statusList.nextSibling);
                }
            });
    }

    // Update status immediately on page load
    updateProcessStatus();
    // Update status every 5 seconds
    setInterval(updateProcessStatus, 5000);
</script>
</body>
</html>
