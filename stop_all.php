<?php
/**
 * stop_all.php — Stops all running background processes.
 * Removes lock/running files and kills PHP processes if PIDs are available.
 * Called via AJAX from the dashboard.
 */
include __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$base_dir = __DIR__ . '/';

$lockFiles = [
    'crawler'     => ['crawler.running', 'crawler.lock'],
    'geturls'     => ['getURLS.running'],
    'getemails'   => ['crawler.getemails.running', 'crawler.lock'],
    'addtomautic' => ['addtomautic.running'],
];

$stopped = [];
$errors = [];

foreach ($lockFiles as $process => $files) {
    foreach ($files as $file) {
        $path = $base_dir . $file;

        // Try to kill the process by PID if it's a .lock file with a PID
        if (str_ends_with($file, '.lock') && is_file($path)) {
            $pid = (int)trim((string)@file_get_contents($path));
            if ($pid > 1) {
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, 15); // SIGTERM
                    usleep(100000); // 100ms grace
                    @posix_kill($pid, 9);  // SIGKILL if still alive
                } else {
                    @exec("kill -15 {$pid} 2>/dev/null");
                    usleep(100000);
                    @exec("kill -9 {$pid} 2>/dev/null");
                }
            }
        }

        // Remove the file
        if (is_file($path)) {
            if (@unlink($path)) {
                $stopped[] = $file;
            } else {
                $errors[] = "Failed to remove {$file}";
            }
        }
    }
}

// Also clean up activity JSON files
@unlink($base_dir . 'crawl_activity.json');
@unlink($base_dir . 'search_activity.json');

echo json_encode([
    'ok' => true,
    'stopped' => $stopped,
    'errors' => $errors,
    'message' => empty($stopped) ? 'No processes were running.' : 'Stopped ' . count($stopped) . ' process(es).',
]);
