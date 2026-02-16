<?php
declare(strict_types=1);

/**
 * log_api.php
 * AJAX endpoint used by admin_live_log.php to read crawler.log incrementally.
 */

require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$logFile = __DIR__ . '/crawler.log';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($offset < 0) $offset = 0;

// Detect "running" state via marker/lock files
$crawlerRunning = false;
if (is_file(__DIR__ . '/crawler.running')) $crawlerRunning = true;
if (is_file(__DIR__ . '/crawler.getemails.running')) $crawlerRunning = true;
if (is_file(__DIR__ . '/crawler.lock')) $crawlerRunning = true;

if (!is_file($logFile)) {
    echo json_encode([
        'ok' => true,
        'chunk' => '',
        'nextOffset' => 0,
        'fileSize' => 0,
        'crawlerRunning' => $crawlerRunning,
        'serverTime' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

$size = filesize($logFile);
if ($size === false) $size = 0;

// If the log file was truncated/rotated, reset the cursor
if ($offset > $size) $offset = 0;

// Limit bytes returned per poll so responses stay small/fast
$maxBytes = 200 * 1024; // 200KB
$toRead = $size - $offset;
if ($toRead < 0) $toRead = 0;

if ($toRead > $maxBytes) {
    // If the viewer fell behind, send only the tail end
    $offset = max(0, $size - $maxBytes);
    $toRead = $size - $offset;
}

$fh = @fopen($logFile, 'rb');
if (!$fh) {
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to open crawler.log (check file permissions).',
    ]);
    exit;
}

if ($offset > 0) {
    @fseek($fh, $offset);
}

$chunk = '';
if ($toRead > 0) {
    $chunk = (string)fread($fh, $toRead);
}

$nextOffset = ftell($fh);
fclose($fh);

if ($nextOffset === false) $nextOffset = $size;

echo json_encode([
    'ok' => true,
    'chunk' => $chunk,
    'nextOffset' => (int)$nextOffset,
    'fileSize' => (int)$size,
    'crawlerRunning' => $crawlerRunning,
    'serverTime' => date('Y-m-d H:i:s'),
]);