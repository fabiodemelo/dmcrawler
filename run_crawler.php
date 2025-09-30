<?php
//version 3.3
// Execute crawler.php in the background
exec("php crawler.php > /dev/null &");
header('Location: index.php?started=1');
exit;
?>
<?php
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
?>
