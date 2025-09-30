<?php
// version 3.5
// Log viewer with Bootstrap theme + shared navbar

// Optional: protect the page if you have auth
// include 'auth_check.php';
include 'db.php';

// Force-flush helpers to stream output in real time (CLI and Browser)
if (!function_exists('force_flush')) {
    function force_flush() {
        // Emit a tiny marker to create a chunk for proxies/buffers (browser)
        if (php_sapi_name() !== 'cli') {
            echo "<!-- FLUSH " . microtime(true) . " -->\n";
        }
        @ob_flush();
        @flush();
    }
}

// Start a streaming UI (browser) and disable buffering everywhere
if (!function_exists('start_streaming_ui')) {
    function start_streaming_ui() {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        @ini_set('zlib.output_compression', '0');
        while (@ob_get_level() > 0) { @ob_end_flush(); }
        @ob_implicit_flush(1);

        if (php_sapi_name() === 'cli') {
            // CLI: nothing to build, just ensure immediate flush
            force_flush();
            return;
        }

        // Anti-buffer headers (Nginx/Proxies)
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Crawler Live</title>';
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">';
        echo '<style>body{padding:10px}.log{height:320px;white-space:pre;overflow:auto;background:#0b1220;color:#d9f99d;border:1px solid #334155;border-radius:8px;padding:10px;font:12px/1.4 ui-monospace,Menlo,Consolas,monospace}</style>';
        echo '</head><body>';
        echo '<div class="mb-2"><b>Live Crawler Output</b></div><div id="l" class="log"></div>';
        echo '<script>var L=document.getElementById("l");function appendLine(t){var b=L.scrollTop+L.clientHeight>=L.scrollHeight-5;L.textContent+=t+"\\n";if(b)L.scrollTop=L.scrollHeight;}</script>';
        // Push a large initial chunk to bypass buffering
        echo str_repeat(' ', 8192), "\n";
        force_flush();
    }
}

// Mirror stream and log writes; always flush
if (!function_exists('stream_message')) {
    function stream_message($msg) {
        $ts = date('Y-m-d H:i:s');
        $line = "[$ts] " . (string)$msg;
        if (php_sapi_name() === 'cli') {
            echo $line . PHP_EOL;
        } else {
            echo "<script>appendLine(" . json_encode($line) . ");</script>\n";
        }
        force_flush();
    }
}

// Provide a fallback logger if missing
if (!function_exists('log_activity')) {
    function log_activity($message) {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] " . (string)$message . "\n";
        @file_put_contents(__DIR__ . '/crawler.log', $line, FILE_APPEND);
        if (php_sapi_name() === 'cli') {
            echo $line;
        }
        if (function_exists('stream_message')) {
            @stream_message((string)$message);
        }
        force_flush();
    }
}

$logFile = __DIR__ . '/crawler.log';
$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    if ($action === 'clear') {
        if (@file_put_contents($logFile, '') !== false) {
            $notice = 'Log cleared.';
        } else {
            $error = 'Failed to clear log file.';
        }
    }
    // Redirect to avoid resubmission on refresh
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $qs   = http_build_query(['n' => $notice, 'e' => $error]);
    header('Location: ' . $base . ($qs ? "?$qs" : ''));
    exit;
}

if (isset($_GET['n'])) $notice = (string)$_GET['n'];
if (isset($_GET['e'])) $error  = (string)$_GET['e'];

// Efficiently read the last chunk of the log (avoid freezing on huge files)
function read_log_tail($path, $maxBytes = 300000) { // ~300 KB
    if (!is_file($path)) return '';
    $size = filesize($path);
    if ($size === 0) return '';
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $offset = ($size > $maxBytes) ? $size - $maxBytes : 0;
    if ($offset > 0) fseek($fh, $offset);
    $data = stream_get_contents($fh);
    fclose($fh);
    // Ensure we start from a new line if we cut mid-line
    if ($offset > 0) {
        $nlPos = strpos($data, "\n");
        if ($nlPos !== false) $data = substr($data, $nlPos + 1);
    }
    return $data;
}

$logText = read_log_tail($logFile);
$logSize = is_file($logFile) ? filesize($logFile) : 0;
$logSizeHuman = $logSize >= 1048576 ? round($logSize / 1048576, 2) . ' MB'
        : ($logSize >= 1024 ? round($logSize / 1024, 2) . ' KB'
                : $logSize . ' B');

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Logs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .logbox {
            width: 100%;
            height: 70vh;
            white-space: pre;
            overflow: auto;
            background: #111;
            color: #0f0;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
        }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Crawler Logs</h1>
        <div class="d-flex gap-2">
            <a href="log.php" class="btn btn-outline-primary btn-sm">Refresh</a>
            <a href="crawler.log" class="btn btn-outline-secondary btn-sm" download>Download</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Clear the entire log file?');">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-danger btn-sm">Clear Log</button>
            </form>
        </div>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-success py-2"><?= htmlspecialchars($notice, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>Showing latest entries</div>
            <div class="text-muted small nowrap">File size: <?= htmlspecialchars($logSizeHuman, ENT_QUOTES) ?></div>
        </div>
        <div class="card-body p-2">
            <div class="logbox" id="logbox"><?= htmlspecialchars($logText, ENT_QUOTES) ?></div>
        </div>
    </div>

    <div class="text-muted mt-2 small">
        Tip: The viewer trims to the most recent ~300 KB to keep the page responsive.
    </div>
</div>

<script>
    // Auto-scroll to bottom on load
    (function() {
        var box = document.getElementById('logbox');
        if (box) {
            box.scrollTop = box.scrollHeight;
        }
    })();
</script>
</body>
</html>