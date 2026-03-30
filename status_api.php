<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

include __DIR__ . '/db.php';

$base_dir = __DIR__ . '/';

function safe_read_started_at($filePath) {
    $raw = @file_get_contents($filePath);
    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false) return (int)$ts;
    }
    $mt = @filemtime($filePath);
    if ($mt !== false) return (int)$mt;
    return null;
}

function pid_is_alive($pid) {
    $pid = (int)$pid;
    if ($pid <= 1) return false;

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    // Can't verify without posix; assume alive if lock exists
    return true;
}

$now = time();

try {
    $tasks = [
        'crawler' => [
            'label' => 'Crawler (Domains)',
            'running_file' => $base_dir . 'crawler.running',
            'pid_file' => $base_dir . 'crawler.lock',
        ],
        'geturls' => [
            'label' => 'Get URLs',
            'running_file' => $base_dir . 'getURLS.running',
        ],
        'getemails' => [
            'label' => 'Get Emails',
            'running_file' => $base_dir . 'crawler.getemails.running',
            'pid_file' => $base_dir . 'crawler.lock',
        ],
        'addtomautic' => [
            'label' => 'Send Emails to Mautic',
            'running_file' => $base_dir . 'addtomautic.running',
        ],
    ];

    $status = [
        'ok' => true,
        'serverTime' => date('Y-m-d H:i:s'),
        'now' => $now,
        'tasks' => [],
    ];

    foreach ($tasks as $key => $cfg) {
        $runningFile = $cfg['running_file'];
        $pidFile = isset($cfg['pid_file']) ? $cfg['pid_file'] : null;

        $running = is_file($runningFile);

        $pid = null;
        if ($pidFile && is_file($pidFile)) {
            $pidRaw = trim((string)@file_get_contents($pidFile));
            $pid = (int)$pidRaw;
            if ($pid <= 1) $pid = null;
        }

        $startedTs = null;
        if ($running) {
            $startedTs = safe_read_started_at($runningFile);
        }

        $elapsed = null;
        if ($running && $startedTs) {
            $elapsed = max(0, $now - $startedTs);
        }

        // If we have POSIX and PID isn't alive, check if it's truly stale
        // (only mark stale if the running file is older than 5 minutes with no PID activity)
        $stale_pid = false;
        if ($running && $pid !== null && function_exists('posix_kill')) {
            if (!pid_is_alive($pid)) {
                // PID is dead — but running file exists. Mark stale, not running.
                $stale_pid = true;
                $running = false;
            }
        }
        // If running file exists but is older than 2 hours, assume orphaned/stale
        if ($running && $startedTs && ($now - $startedTs) > 7200) {
            $stale_pid = true;
            $running = false;
        }

        $status['tasks'][$key] = [
            'label' => $cfg['label'],
            'running' => $running,
            'stale_pid' => $stale_pid,
            'started_at' => $startedTs ? date('Y-m-d H:i:s', $startedTs) : null,
            'elapsed_seconds' => $elapsed,
            'pid' => $pid,
            'running_file' => basename($runningFile),
        ];
    }

    // Live activity data (what's happening right now)
    $liveActivity = [];
    $crawlFile = $base_dir . 'crawl_activity.json';
    if (is_file($crawlFile)) {
        $raw = @file_get_contents($crawlFile);
        $data = json_decode($raw, true);
        if (is_array($data)) $liveActivity['crawl'] = $data;
    }
    $searchFile = $base_dir . 'search_activity.json';
    if (is_file($searchFile)) {
        $raw = @file_get_contents($searchFile);
        $data = json_decode($raw, true);
        if (is_array($data)) $liveActivity['search'] = $data;
    }
    $status['live'] = $liveActivity;

    // Add last crawl run info
    $lastRun = null;
    try {
        $lr = $conn->query("SELECT cm.*, d.domain FROM crawl_metrics cm JOIN domains d ON cm.domain_id = d.id ORDER BY cm.id DESC LIMIT 1");
        if ($lr && $row = $lr->fetch_assoc()) {
            $lastRun = [
                'domain' => $row['domain'],
                'pages' => (int)$row['pages_crawled'],
                'emails' => (int)$row['valid_emails'],
                'rejected' => (int)$row['rejected_emails'],
                'duration' => (int)$row['total_time_seconds'],
                'stop_reason' => $row['stop_reason'],
                'ended_at' => $row['run_ended_at'],
            ];
        }
    } catch (Throwable $e) {}
    $status['last_run'] = $lastRun;

    echo json_encode($status);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'status_api failed: ' . $e->getMessage(),
    ]);
    exit;
}