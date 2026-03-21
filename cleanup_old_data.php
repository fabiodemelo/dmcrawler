<?php
/**
 * cleanup_old_data.php — Phase 2G
 * Prunes old crawl_pages, email_rejections, and crawl_metrics data.
 * Run periodically (e.g., weekly cron) to keep the database lean.
 *
 * Usage: php cleanup_old_data.php [--days=30] [--dry-run]
 */
include __DIR__ . '/db.php';

// Parse CLI arguments
$dryRun = in_array('--dry-run', $argv ?? []);
$days = 30;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(7, (int)$m[1]);
    }
}

$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

echo "Cleanup — Pruning data older than {$days} days (before {$cutoff})\n";
if ($dryRun) echo "DRY RUN — no data will be deleted.\n";
echo str_repeat('-', 60) . "\n";

$tables = [
    'crawl_pages' => 'crawled_at',
    'email_rejections' => 'created_at',
    'crawl_metrics' => 'run_started_at',
];

$totalDeleted = 0;

foreach ($tables as $table => $dateCol) {
    // Check if table exists
    $check = @$conn->query("SELECT 1 FROM `{$table}` LIMIT 0");
    if (!$check) {
        echo "  [{$table}] Table does not exist — skipping.\n";
        continue;
    }

    // Count rows to delete
    $res = $conn->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$dateCol}` < '{$conn->real_escape_string($cutoff)}'");
    $count = $res ? (int)$res->fetch_assoc()['c'] : 0;

    if ($count === 0) {
        echo "  [{$table}] No rows older than {$days} days.\n";
        continue;
    }

    if ($dryRun) {
        echo "  [{$table}] Would delete {$count} rows.\n";
    } else {
        $conn->query("DELETE FROM `{$table}` WHERE `{$dateCol}` < '{$conn->real_escape_string($cutoff)}'");
        $deleted = $conn->affected_rows;
        echo "  [{$table}] Deleted {$deleted} rows.\n";
        $totalDeleted += $deleted;
    }
}

// Also clean up old SerpAPI cache files
$cacheDir = __DIR__ . '/cache/serp';
$cacheDeleted = 0;
if (is_dir($cacheDir)) {
    $cutoffTime = time() - ($days * 86400);
    $files = glob($cacheDir . '/*.json');
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            if ($dryRun) {
                $cacheDeleted++;
            } else {
                if (unlink($file)) $cacheDeleted++;
            }
        }
    }
    if ($cacheDeleted > 0) {
        $action = $dryRun ? 'Would delete' : 'Deleted';
        echo "  [cache/serp] {$action} {$cacheDeleted} cached JSON files.\n";
    } else {
        echo "  [cache/serp] No stale cache files.\n";
    }
}

echo str_repeat('-', 60) . "\n";
if ($dryRun) {
    echo "DRY RUN complete. No changes made.\n";
} else {
    echo "Cleanup complete. Total DB rows deleted: {$totalDeleted}, Cache files: {$cacheDeleted}\n";
}
