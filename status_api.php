<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

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

        // If we have POSIX and PID isn't alive, mark stale lock as not running
        if ($running && $pid !== null && function_exists('posix_kill')) {
            if (!pid_is_alive($pid)) {
                $running = false;
            }
        }

        $status['tasks'][$key] = [
            'label' => $cfg['label'],
            'running' => $running,
            'started_at' => $startedTs ? date('Y-m-d H:i:s', $startedTs) : null,
            'elapsed_seconds' => $elapsed,
            'pid' => $pid,
            'running_file' => basename($runningFile),
        ];
    }

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