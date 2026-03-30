<?php
/**
 * cleanup_bad_emails.php — One-time cleanup of bad emails already in the database.
 *
 * Uses the same is_clean_email() gatekeeper as the crawler and Mautic sync.
 * Marks invalid emails with ma = -1 so they are never sent to Mautic.
 *
 * Run from CLI:   php cleanup_bad_emails.php
 * Run from CLI (dry run):   php cleanup_bad_emails.php --dry-run
 * Run from browser: visit cleanup_bad_emails.php (requires login)
 */

// Auth check for browser access, skip for CLI
if (php_sapi_name() !== 'cli') {
    include __DIR__ . '/auth_check.php';
}
include __DIR__ . '/db.php';

$dryRun = false;
if (php_sapi_name() === 'cli') {
    $dryRun = in_array('--dry-run', $argv ?? []);
} else {
    $dryRun = isset($_GET['dry_run']);
}

$isCli = php_sapi_name() === 'cli';

function out(string $msg, bool $isCli): void {
    if ($isCli) {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
    @ob_flush(); @flush();
}

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Email Cleanup</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">';
    echo '</head><body class="p-4"><div class="container"><h2>Email Cleanup</h2><pre>';
}

out("=== Email Cleanup " . ($dryRun ? '(DRY RUN)' : '(LIVE)') . " ===", $isCli);
out("Started: " . date('Y-m-d H:i:s'), $isCli);
out("", $isCli);

// --- Phase 1: Mark bad emails that are still pending (ma IS NULL or ma = 0) ---
out("Phase 1: Scanning pending emails for invalid format...", $isCli);

$batchSize = 1000;
$offset = 0;
$totalScanned = 0;
$totalMarked = 0;
$samples = [];

while (true) {
    $res = $conn->query("SELECT id, email FROM emails WHERE (ma IS NULL OR ma = 0) ORDER BY id ASC LIMIT {$batchSize} OFFSET {$offset}");
    if (!$res || $res->num_rows === 0) break;

    while ($row = $res->fetch_assoc()) {
        $totalScanned++;
        $email = trim(strtolower($row['email']));
        $gate = is_clean_email($email);

        if (!$gate['valid']) {
            $totalMarked++;
            if (count($samples) < 20) {
                $samples[] = "  ID {$row['id']}: {$email} — {$gate['reason']}";
            }
            if (!$dryRun) {
                $stmt = $conn->prepare("UPDATE emails SET ma = -1 WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $row['id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    $res->free();
    $offset += $batchSize;

    // Progress every 5000
    if ($totalScanned % 5000 === 0) {
        out("  ...scanned {$totalScanned}, marked {$totalMarked} so far", $isCli);
    }
}

out("", $isCli);
out("Pending emails scanned: {$totalScanned}", $isCli);
out("Invalid emails " . ($dryRun ? 'found' : 'marked ma=-1') . ": {$totalMarked}", $isCli);

if (!empty($samples)) {
    out("", $isCli);
    out("Sample bad emails:", $isCli);
    foreach ($samples as $s) {
        out($s, $isCli);
    }
    if ($totalMarked > 20) {
        out("  ... and " . ($totalMarked - 20) . " more", $isCli);
    }
}

// --- Phase 2: Count already-failed emails that Mautic rejected (ma = 0 but would fail again) ---
out("", $isCli);
out("Phase 2: Checking for previously synced emails with bad format...", $isCli);

$alreadySyncedBad = 0;
$res = $conn->query("SELECT COUNT(*) AS c FROM emails WHERE ma = 1");
$syncedTotal = $res ? (int)$res->fetch_assoc()['c'] : 0;

// Scan synced emails too — just report, don't change their ma status
$offset = 0;
while (true) {
    $res = $conn->query("SELECT id, email FROM emails WHERE ma = 1 ORDER BY id ASC LIMIT {$batchSize} OFFSET {$offset}");
    if (!$res || $res->num_rows === 0) break;

    while ($row = $res->fetch_assoc()) {
        $email = trim(strtolower($row['email']));
        $gate = is_clean_email($email);
        if (!$gate['valid']) {
            $alreadySyncedBad++;
        }
    }
    $res->free();
    $offset += $batchSize;
}

out("Already-synced emails scanned: {$syncedTotal}", $isCli);
out("Bad format among synced (already in Mautic): {$alreadySyncedBad}", $isCli);

// --- Phase 3: Summary stats ---
out("", $isCli);
out("=== Database Email Stats ===", $isCli);

$stats = [
    'Total emails' => "SELECT COUNT(*) AS c FROM emails",
    'Pending (ma=NULL/0)' => "SELECT COUNT(*) AS c FROM emails WHERE ma IS NULL OR ma = 0",
    'Synced (ma=1)' => "SELECT COUNT(*) AS c FROM emails WHERE ma = 1",
    'Invalid format (ma=-1)' => "SELECT COUNT(*) AS c FROM emails WHERE ma = -1",
    'Mautic rejected (ma=-2)' => "SELECT COUNT(*) AS c FROM emails WHERE ma = -2",
];

foreach ($stats as $label => $sql) {
    $res = $conn->query($sql);
    $count = $res ? (int)$res->fetch_assoc()['c'] : 0;
    out(sprintf("  %-30s %s", $label, number_format($count)), $isCli);
}

out("", $isCli);
if ($dryRun) {
    out("This was a DRY RUN. No changes were made.", $isCli);
    out("Run without --dry-run to apply changes.", $isCli);
} else {
    out("Cleanup complete. Invalid emails marked ma=-1 and will not be sent to Mautic.", $isCli);
}
out("Finished: " . date('Y-m-d H:i:s'), $isCli);

if (!$isCli) {
    echo '</pre></div></body></html>';
}
