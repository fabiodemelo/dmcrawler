```php
<?php
// crawler.php - Crawls websites for emails with live progress and settings.
// Requires: db.php for MySQLi connection ($conn)
// Settings loaded from `settings` table (id=1):
//   page_delay_min, page_delay_max, domain_delay_min, domain_delay_max, max_depth, min_pages_crawled

declare(strict_types=1);
include 'db.php';

// --- START LOCK FILE MANAGEMENT ---
// Determine the lock file name based on how it was invoked from the dashboard
// Default lock file for general 'crawler' task
$lock_file_name = 'crawler.running';

// Check if a specific mode was passed (e.g., from run_get_emails.php)
if (isset($argv[1])) {
    $arg = explode('=', $argv[1]);
    if (count($arg) === 2 && $arg[0] === '--mode' && $arg[1] === 'email_extraction') {
        $lock_file_name = 'crawler.getemails.running'; // Specific lock for "Get Emails" task
    }
}
$lock_file = __DIR__ . '/' . $lock_file_name;

// Create lock file at the very beginning of the script execution, storing start time
file_put_contents($lock_file, date('Y-m-d H:i:s'));

// Register a shutdown function to ensure the lock file is removed even if the script crashes
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) {
        unlink($lock_file);
        // Optionally, log that the script finished or crashed
        // error_log("Script finished or crashed, removed lock file: " . $lock_file);
    }
});
// --- END LOCK FILE MANAGEMENT ---

// ... rest of your existing crawler.php code ...

// IMPORTANT: The existing code in crawler.php might be within a main execution block or function.
// Ensure the lock file management code above is placed right after 'include db.php;'
// and before any core logic of the crawler starts to execute, especially anything that might 'exit' early.

// For example, if your crawler.php has a main() function:
// function main() {
//    // ... all your crawler logic ...
// }
// Then make sure the lock file code is outside and before the call to main().
// If it's just procedural code, place it near the top.

// ======= Core Utilities (Must be defined first) =======

// Helper to ensure DB connection is available
if (!function_exists('ensure_db_connection')) {
    function ensure_db_connection(): bool {
        global $conn;
        if ($conn instanceof mysqli && $conn->ping()) return true;
        // Attempt to re-establish or re-include if needed (e.g., after long idle or in CLI)
        $dbPath = __DIR__ . '/db.php';
        if (file_exists($dbPath)) {
            include $dbPath; // Use include, as require_once might not re-execute if already included
        }
        return ($conn instanceof mysqli && $conn->ping());
    }
}

// Global setting getter (unified and robust)
if (!function_exists('get_setting_value')) {
    function get_setting_value($field) {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            // Log this error as get_setting_value might be used for debugging
            if (function_exists('log_activity')) {
                log_activity("get_setting_value: Invalid field name attempted: '{$field}'");
            }
            return null;
        }
        if (!ensure_db_connection()) {
            if (function_exists('log_activity')) {
                log_activity("get_setting_value: No DB connection for field '{$field}'");
            }
            return null;
        }
        global $conn;
        // Use backticks for field names to prevent SQL reserved word issues
        $res = $conn->query("SELECT `{$field}` AS v FROM settings WHERE id = 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return $row['v'];
        }
        return null;
    }
}

// Log activity to file and potentially stream
if (!function_exists('log_activity')) {
    function log_activity($message) {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] " . (string)$message . "\n";
        @file_put_contents(__DIR__ . '/crawler.log', $line, FILE_APPEND);
        if (php_sapi_name() === 'cli') {
            echo $line;
        }
        if (function_exists('stream_message')) { // Check if stream_message is defined yet
            @stream_message((string)$message);
        }
        // Force flush in case this is called early
        if (function_exists('force_flush')) {
            force_flush();
        }
    }
}

// Returns the configured minimum pages threshold (cached on first call)
if (!function_exists('get_min_pages_threshold')) {
    function get_min_pages_threshold() {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = 0; // Default to 0 if not found or error
        try {
            // Use the generic get_setting_value
            $val = get_setting_value('min_pages_crawled');
            if ($val !== null) {
                $intVal = (int)$val;
                if ($intVal >= 0) $cached = $intVal;
            }
        } catch (Exception $e) {
            log_activity("get_min_pages_threshold error: " . $e->getMessage());
        }
        return $cached;
    }
}

// Mark a domain as crawled (idempotent) once threshold reached
if (!function_exists('mark_domain_crawled_once')) {
    function mark_domain_crawled_once($domain_id) {
        static $marked = [];
        if (isset($marked[$domain_id])) return false; // Already marked in this run

        if (!ensure_db_connection()) {
            log_activity("mark_domain_crawled_once: No DB connection for domain {$domain_id}");
            return false;
        }
        global $conn;
        $domain_id = (int)$domain_id;
        if ($domain_id <= 0) return false;

        $sql = "UPDATE `domains`
                   SET `crawled` = 1,
                       `date_crawled` = IFNULL(`date_crawled`, NOW())
                 WHERE `id` = ? AND `crawled` = 0
                 LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            log_activity("mark_domain_crawled_once: prepare failed for domain {$domain_id}: " . $conn->error);
            return false;
        }
        $stmt->bind_param('i', $domain_id);
        $ok = $stmt->execute();
        if ($ok === false) {
            log_activity("mark_domain_crawled_once: execute failed for domain {$domain_id}: " . $stmt->error);
            $stmt->close();
            return false;
        }
        // Note: autocommit might be off from `db.php` if it uses PDO, but $conn is mysqli here.
        // For mysqli, queries are typically autocommitted by default unless explicitly disabled.
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $marked[$domain_id] = true; // Mark as done for this script run
            log_activity("Domain {$domain_id} marked as crawled (min_pages_crawled reached).");
            return true;
        }
        // If it was already crawled by another process or earlier in this one, update static cache
        $res = $conn->query("SELECT `crawled` FROM `domains` WHERE `id` = {$domain_id} LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            if ((int)$row['crawled'] === 1) {
                $marked[$domain_id] = true;
            }
        }
        return false;
    }
}

// Persist progress (urls_crawled, emails_found)
if (!function_exists('update_domain_progress')) {
    function update_domain_progress($domain_id, $emails_found, $urls_crawled) {
        if (!ensure_db_connection()) {
            log_activity("update_domain_progress: No DB connection for domain {$domain_id}");
            return false;
        }
        global $conn;
        $domain_id = (int)$domain_id;
        $emails_found = (int)$emails_found;
        $urls_crawled = (int)$urls_crawled;
        if ($domain_id <= 0) return false;

        $stmt = $conn->prepare("UPDATE `domains` SET `emails_found` = ?, `urls_crawled` = ? WHERE `id` = ? LIMIT 1");
        if ($stmt === false) {
            log_activity("update_domain_progress: prepare failed for domain {$domain_id}: " . $conn->error);
            return false;
        }
        $stmt->bind_param('iii', $emails_found, $urls_crawled, $domain_id);
        $ok = $stmt->execute();
        if ($ok === false) {
            log_activity("update_domain_progress: execute failed for domain {$domain_id}: " . $stmt->error);
            $stmt->close();
            return false;
        }
        // autocommit not an issue here for mysqli usually
        $stmt->close();
        return true;
    }
}

// Force-flush any output buffers (crucial for live streaming)
if (!function_exists('force_flush')) {
    function force_flush() {
        if (php_sapi_name() !== 'cli') {
            echo "<!-- FLUSH " . microtime(true) . " -->\n";
        }
        @ob_flush(); @flush();
    }
}

// Stream messages to CLI and/or browser console
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

// Start browser UI (includes HTML/CSS/JS shell for live log)
if (!function_exists('start_streaming_ui')) {
    function start_streaming_ui() {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        @ini_set('zlib.output_compression', '0');
        while (@ob_get_level() > 0) { @ob_end_flush(); }
        @ob_implicit_flush(1); // FIX: PHP 7.x expects int (1/0), not boolean true

        if (php_sapi_name() === 'cli') {
            force_flush(); // Ensure output is not buffered in CLI either
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Buffering: no'); // Nginx specific
        header('Content-Encoding: none'); // Disable gzip

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Crawler Live</title>';
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">';
        echo '<style>body{padding:10px}.log{height:320px;white-space:pre;overflow:auto;background:#0b1220;color:#d9f99d;border:1px solid #334155;border-radius:8px;padding:10px;font:12px/1.4 ui-monospace,Menlo,Consolas,monospace}</style>';
        echo '</head><body>';
        echo '<div class="mb-2"><b>Live Crawler Output</b></div><div id="l" class="log"></div>';
        echo '<script>var L=document.getElementById("l");function appendLine(t){var b=L.scrollTop+L.clientHeight>=L.scrollHeight-5;L.textContent+=t+"\\n";if(b)L.scrollTop=L.scrollHeight;}</script>';
        echo str_repeat(' ', 8192), "\n"; // Padding to bypass initial buffering
        force_flush();
    }
}

// End browser UI (append closing tags)
if (!function_exists('end_streaming_ui')) {
    function end_streaming_ui() {
        if (php_sapi_name() === 'cli') return;
        echo '</body></html>';
        force_flush();
    }
}

// Stream stats to CLI and/or browser HUD
if (!function_exists('stream_stats_crawler')) {
    function stream_stats_crawler($domain, $pages, $emails, $t0) {
        $elapsed = max(0.001, microtime(true) - (float)$t0);
        $rate = sprintf('%.2f', ($pages > 0 ? $pages / $elapsed : 0.0));
        if (php_sapi_name() === 'cli') {
            echo "[STATS] domain=" . (string)$domain . " | pages=" . (int)$pages . " | emails=" . (int)$emails . " | elapsed=" . (int)$elapsed . "s | rate=" . $rate . " p/s" . PHP_EOL;
            force_flush();
            return;
        }
        // Browser HUD update script (initializes once, then updates)
        echo "<script>(function(d,p,e,s,r){if(!window.__hud){var c=document.createElement('div');c.id='crawlHUD';c.style.cssText='position:fixed;right:10px;top:10px;background:#0b1220;color:#d9f99d;border:1px solid #334155;border-radius:8px;padding:10px 12px;font:12px/1.3 ui-monospace,Menlo,Consolas,monospace;z-index:2147483647;box-shadow:0 2px 10px rgba(0,0,0,.4)';c.innerHTML='<div style=\"font-weight:700;margin-bottom:6px\">Crawler Live</div><div>Domain: <span id=\"h_d\">-</span></div><div>Pages: <span id=\"h_p\">0</span></div><div>Emails: <span id=\"h_e\">0</span></div><div>Elapsed: <span id=\"h_s\">0s</span></div><div>Rate: <span id=\"h_r\">0.00</span> p/s</div>';document.body.appendChild(c);window.__hud={d:document.getElementById('h_d'),p:document.getElementById('h_p'),e:document.getElementById('h_e'),s:document.getElementById('h_s'),r:document.getElementById('h_r')};}var H=window.__hud;H.d.textContent=d;H.p.textContent=String(p);H.e.textContent=String(e);H.s.textContent=(s<60? (s+'s') : (Math.floor(s/60)+'m '+(s%60)+'s'));H.r.textContent=r;})(" . json_encode((string)$domain) . "," . (int)$pages . "," . (int)$emails . "," . (int)$elapsed . "," . json_encode($rate) . ");</script>\n";
        force_flush();
    }
}

// cURL progress for download heartbeats
if (!function_exists('curl_progress_echo')) {
    function curl_progress_echo($resource, $dl_total, $dl_now, $ul_total, $ul_now) {
        static $last = 0;
        $now = time();
        if ($now !== $last) {
            $kbNow = $dl_now > 0 ? round($dl_now / 1024, 1) : 0;
            $kbTot = $dl_total > 0 ? round($dl_total / 1024, 1) : 0;
            stream_message("... downloading {$kbNow}KB" . ($kbTot ? " of {$kbTot}KB" : ''));
            $last = $now;
        }
        // Return 0 to continue transfer
        return 0;
    }
}

// Single-instance lock to prevent duplicate crawler runs
if (!function_exists('acquire_lock')) {
    function acquire_lock() {
        $lockFile = __DIR__ . '/crawler.lock';
        $fh = @fopen($lockFile, 'c+');
        if (!$fh) return false;
        // LOCK_EX (exclusive lock) | LOCK_NB (non-blocking)
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            @fclose($fh); // Close if cannot acquire
            return false;
        }
        ftruncate($fh, 0); // Clear file content
        fwrite($fh, (string)getmypid()); // Write PID
        return $fh; // Keep handle open to hold the lock
    }
}
if (!function_exists('release_lock')) {
    function release_lock($fh) {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN); // Release the lock
            @fclose($fh);
            // Optionally unlink($lockFile) if you want to remove the file after,
            // but keeping it helps detect orphaned locks from crashed scripts.
        }
    }
}

// ======= New Email Validation Helpers =======
if (!function_exists('is_valid_email_format')) {
    function is_valid_email_format($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
if (!function_exists('has_bad_extension')) {
    function has_bad_extension($email) {
        // Flag common file extensions that indicate a bad scrape (e.g., image names mistaken for emails)
        return preg_match('/\.(jpg|jpeg|png|gif|pdf|zip|mp4|avi|mov)\b/i', $email) === 1;
    }
}
// ======= END New Email Validation Helpers =======

    // PHP 7.1 compatibility helper (str_ends_with is PHP 8+)
    if (!function_exists('ends_with')) {
        function ends_with($haystack, $needle) {
            $haystack = (string)$haystack;
            $needle = (string)$needle;
            if ($needle === '') return true;
            $len = strlen($needle);
            return $len === 0 ? true : (substr($haystack, -$len) === $needle);
        }
    }

    // ======= New Email Validation Helpers =======
    if (!function_exists('is_valid_email_format')) {
        function is_valid_email_format($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }
    }
if (!function_exists('has_bad_extension')) {
    function has_bad_extension($email) {
        // Flag common file extensions that indicate a bad scrape (e.g., image names mistaken for emails)
        return preg_match('/\.(jpg|jpeg|png|gif|pdf|zip|mp4|avi|mov)\b/i', $email) === 1;
    }
}
if (!function_exists('has_allowed_extension')) {
    function has_allowed_extension($email) {
        $allowed_extensions = [
            '.com', '.net', '.org', '.co', '.io', '.us', '.gov', '.ca', '.edu', '.mil', '.ai', '.dev', '.app', '.me', '.biz', '.tech'
        ];
        $email_parts = explode('@', $email);
        if (count($email_parts) < 2) {
            return false; // Not a valid email structure
        }
        $domain = $email_parts[1];
        foreach ($allowed_extensions as $ext) {
            if (ends_with($domain, $ext)) { // FIX: PHP 7.1 compatible
                return true;
            }
        }
        return false;
    }
}
// ======= END New Email Validation Helpers =======

// ======= URL Normalization =======
if (!function_exists('normalize_url')) {
    function normalize_url($base, $link) {
        // Return absolute URL as is
        if (preg_match('/^https?:\/\//i', $link)) {
            return $link;
        }
        // Handle root-relative links
        if (substr($link, 0, 1) == '/') {
            $parsed_base = parse_url($base);
            return ($parsed_base['scheme'] ?? 'http') . '://' . ($parsed_base['host'] ?? '') . $link;
        }
        // Handle path-relative links
        $parsed_base = parse_url($base);
        $scheme = $parsed_base['scheme'] ?? 'http';
        $host = $parsed_base['host'] ?? '';
        $path = dirname($parsed_base['path'] ?? '/');
        // Clean up "./", "../", etc.
        $path_parts = explode('/', $path);
        $link_parts = explode('/', $link);
        $new_path_parts = [];
        foreach ($path_parts as $part) {
            if ($part === '' || $part === '.') continue;
            $new_path_parts[] = $part;
        }
        foreach ($link_parts as $part) {
            if ($part === '..') {
                array_pop($new_path_parts);
            } elseif ($part !== '' && $part !== '.') {
                $new_path_parts[] = $part;
            }
        }
        $clean_path = '/' . implode('/', $new_path_parts);
        return $scheme . '://' . $host . $clean_path;
    }
}

// ======= Main Crawler Logic =======

    function crawl_page($url, $domain, &$visited, &$email_count, $domain_id, $page_delay, $depth, $max_depth) {
        if ($depth > $max_depth) {
            log_activity("Max depth reached at $url");
            stream_message("Max depth reached at $url");
            if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, count($visited), $email_count, $GLOBALS['__crawl_t0']);
            return;
        }

        $url = strtok($url, '#'); // Remove fragment identifiers

        // FIX: fully decode HTML entities (handles &amp;amp; chains)
        $rawUrl = $url;
        for ($i = 0; $i < 5; $i++) {
            $decoded = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            if ($decoded === $url) break;
            $url = $decoded;
        }
        $url = str_replace('amp;', '', $url); // defensive cleanup for broken markup

        if (isset($visited[$url])) return;
        $visited[$url] = true;

        $pagesCrawled = count($visited);
        $minPages = get_min_pages_threshold();
        $thresholdReached = ($minPages > 0 && $pagesCrawled >= $minPages);
        if ($thresholdReached) {
            mark_domain_crawled_once($domain_id);
        }

        // Log the cleaned URL so you can confirm it’s fixed
        log_activity("Visiting page: $url (Depth: $depth)");
        stream_message("Visiting page: $url (Depth: $depth) | Pages crawled so far: {$pagesCrawled}");
        if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, $pagesCrawled, $email_count, $GLOBALS['__crawl_t0']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.google.com/');
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 300);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 8);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'curl_progress_echo');

        $html = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false) {
            log_activity("Error fetching page: $url (cURL: $curl_err)");
            stream_message("Error fetching page: $url");
            update_domain_progress($domain_id, $email_count, count($visited));
            if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, count($visited), $email_count, $GLOBALS['__crawl_t0']);
            return;
        }
        if ($http_code >= 400) {
            log_activity("HTTP $http_code at $url");
            stream_message("HTTP $http_code at $url");
            update_domain_progress($domain_id, $email_count, count($visited));
            if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, count($visited), $email_count, $GLOBALS['__crawl_t0']);
            return;
        }

        $decoded_html = html_entity_decode($html);

        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $decoded_html, $matches);
        global $conn;
        $emails = array_unique($matches[0]);
        foreach ($emails as $email) {
            // Basic cleanup: remove leading/trailing whitespace and ensure lowercase for canonical form
            $email = trim(strtolower($email));

            // Validate email format and check for bad extensions before processing
            if (!is_valid_email_format($email) || has_bad_extension($email)) {
                log_activity("Skipping invalid or malformed email: {$email} on {$url}");
                continue; // Skip to the next email if invalid
            }

            $email = $conn->real_escape_string($email);
            $check_res = $conn->query("SELECT id FROM emails WHERE email = '$email' AND domain_id = $domain_id");
            if ($check_res->num_rows == 0) {
                $conn->query("INSERT INTO emails (domain_id, name, email) VALUES ($domain_id, '', '$email')");
                log_activity("Found email: $email on $url");
                stream_message("Found email: $email on $url");
                $email_count++;
                if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, count($visited), $email_count, $GLOBALS['__crawl_t0']);
            }
        }

        update_domain_progress($domain_id, $email_count, count($visited));
        if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, count($visited), $email_count, $GLOBALS['__crawl_t0']);

        preg_match_all('/<a\s+(?:[^>]*?\s+)?href=["\']([^"\']+)["\']/i', $html, $links);
        $totalLinks = isset($links[1]) ? count($links[1]) : 0;
        stream_message("Discovered {$totalLinks} links on: $url");
        force_flush();

        $sleepFor = rand($page_delay[0], $page_delay[1]);
        if ($sleepFor > 0) {
            $step = max(1, min(2, $sleepFor));
            for ($left = $sleepFor; $left > 0; $left -= $step) {
                $show = max(0, $left - $step);
                stream_message("Waiting ~{$show}s before following links...");
                if (isset($GLOBALS['__crawl_t0'])) stream_stats_crawler($domain, count($visited), $email_count, $GLOBALS['__crawl_t0']);
                force_flush();
                sleep($step);
            }
        }

        foreach ($links[1] as $link) {
            // FIX: decode href entities too
            for ($i = 0; $i < 5; $i++) {
                $decoded = html_entity_decode($link, ENT_QUOTES, 'UTF-8');
                if ($decoded === $link) break;
                $link = $decoded;
            }
            $link = str_replace('amp;', '', $link);

            if (stripos($link, 'cdn-cgi') !== false || stripos($link, 'mailto:') === 0 || preg_match('/\.(jpg|jpeg|png|gif|pdf|zip|css|js)$/i', $link)) {
                continue;
            }

            $absolute_link = normalize_url($url, $link);
            $parsed = parse_url($absolute_link);
            if (isset($parsed['host']) && str_replace('www.', '', $parsed['host']) !== str_replace('www.', '', $domain)) continue;

            // FIX: depth must increment (do NOT reset to 0)
            crawl_page($absolute_link, $domain, $visited, $email_count, $domain_id, $page_delay, $depth + 1, $max_depth);
        }
    }

// ======= Main Execution Block =======
start_streaming_ui();

$__lock = acquire_lock();
if ($__lock === false) {
    stream_message("Another crawler instance is already running. Exiting.");
    end_streaming_ui();
    exit(1);
}

// Load settings for the crawler
$page_delay = [
    (int) (get_setting_value('page_delay_min') ?? 1),
    (int) (get_setting_value('page_delay_max') ?? 3)
];
if ($page_delay[0] < 0) $page_delay[0] = 0;
if ($page_delay[1] < $page_delay[0]) $page_delay[1] = $page_delay[0];

$domain_delay = [
    (int) (get_setting_value('domain_delay_min') ?? 1), // minutes
    (int) (get_setting_value('domain_delay_max') ?? 2)
];
if ($domain_delay[0] < 0) $domain_delay[0] = 0;
if ($domain_delay[1] < $domain_delay[0]) $domain_delay[1] = $domain_delay[0];

$max_depth = (int)(get_setting_value('max_depth') ?? 3);
if ($max_depth < 0) $max_depth = 0;

// New email notification toggles for crawler
$EMAIL_TO = get_setting_value('email_to') ?? '';
$EMAIL_FROM = get_setting_value('email_from') ?? 'no-reply@demelos.com';
$EMAIL_SUBJ_PREFIX = get_setting_value('email_subj_prefix') ?? 'Crawler Report';
$ENABLE_EMAIL_CRAWLER_SUCCESS = (int)(get_setting_value('enable_email_crawler_success') ?? 1);
$ENABLE_EMAIL_CRAWLER_ERROR = (int)(get_setting_value('enable_email_crawler_error') ?? 1);

$crawler_errors = []; // Collect errors during this run

if (!ensure_db_connection()) {
    $error_msg = "FATAL: Could not establish database connection. Exiting.";
    stream_message($error_msg);
    $crawler_errors[] = $error_msg;
    release_lock($__lock);
    end_streaming_ui();
    exit(1);
}

$domains_processed_count = 0;
$total_emails_found_in_run = 0;

global $conn;
$res = $conn->query("SELECT * FROM domains WHERE crawled = 0 AND donot = 0 ORDER BY priority DESC, id ASC LIMIT 1");
if ($res === false) {
    $error_msg = "DB Error fetching domain: " . $conn->error;
    stream_message($error_msg);
    $crawler_errors[] = $error_msg;
    release_lock($__lock);
    end_streaming_ui();
    exit(1);
}

if ($row = $res->fetch_assoc()) {
    $domains_processed_count++;
    $domain_id = (int)$row['id'];
    $domain_to_crawl = (string)$row['domain'];

    // Ensure starting URL is absolute with scheme
    $start_url = preg_match('/^https?:\/\//i', $domain_to_crawl) ? $domain_to_crawl : ("https://" . $domain_to_crawl);
    $host_domain = (string)parse_url($start_url, PHP_URL_HOST);

    if ($host_domain === '') {
        $error_msg = "Invalid domain/start URL: {$start_url}";
        log_activity($error_msg);
        stream_message($error_msg);
        $crawler_errors[] = $error_msg;
    } else {
        $GLOBALS['__crawl_t0'] = microtime(true);

        log_activity("START Crawl domain: $host_domain");
        stream_message("=== START Crawl: $host_domain ===");
        stream_stats_crawler($host_domain, 0, 0, $GLOBALS['__crawl_t0']);

        $visited = [];
        $email_count = 0;

        crawl_page($start_url, $host_domain, $visited, $email_count, $domain_id, $page_delay, 0, $max_depth);

        $urls_crawled_count = count($visited);
        $total_emails_found_in_run += $email_count;

        $update_stmt = $conn->prepare("UPDATE domains SET crawled = 1, date_crawled = NOW(), emails_found = ?, urls_crawled = ? WHERE id = ?");
        if ($update_stmt) {
            $update_stmt->bind_param('iii', $email_count, $urls_crawled_count, $domain_id);
            if (!$update_stmt->execute()) {
                $error_msg = "DB Error updating domain {$domain_id}: " . $update_stmt->error;
                log_activity($error_msg);
                $crawler_errors[] = $error_msg;
            }
            $update_stmt->close();
        } else {
            $error_msg = "DB Error preparing update for domain {$domain_id}: " . $conn->error;
            log_activity($error_msg);
            $crawler_errors[] = $error_msg;
        }

        log_activity("END Crawl domain: $host_domain. Found $email_count emails and crawled $urls_crawled_count URLs.");
        stream_message("=== END Crawl: $host_domain | Emails: $email_count | Pages: $urls_crawled_count ===");
        stream_stats_crawler($host_domain, $urls_crawled_count, $email_count, $GLOBALS['__crawl_t0']);

        $domainSleepSeconds = rand($domain_delay[0] * 60, $domain_delay[1] * 60);
        if ($domainSleepSeconds > 0) {
            stream_message("Waiting for " . $domainSleepSeconds . "s before next domain.");
            sleep($domainSleepSeconds);
        }
    }
} else {
    stream_message("No domains found for crawling. Exiting.");
}

release_lock($__lock);
end_streaming_ui();

// --- Send final summary email ---
$summary_subject_suffix = (empty($crawler_errors) ? 'Success' : 'Completed with Errors');
$summary_subject = $EMAIL_SUBJ_PREFIX . ' - Crawler (' . ($domains_processed_count > 0 ? ('Domains Processed: ' . $domains_processed_count) : 'No Domains Processed') . ') - ' . $summary_subject_suffix;

$summary_body = [];
$summary_body[] = "Crawler Run Summary (" . date('Y-m-d H:i:s') . ")";
$summary_body[] = "------------------------------------------";
$summary_body[] = "Domains Processed: " . $domains_processed_count;
$summary_body[] = "Total Emails Found in Run: " . $total_emails_found_in_run;
$summary_body[] = "Errors Encountered: " . (empty($crawler_errors) ? 'None' : count($crawler_errors));

if (!empty($crawler_errors)) {
    $summary_body[] = "";
    $summary_body[] = "--- Details of Errors ---";
    foreach ($crawler_errors as $err) {
        $summary_body[] = "- " . $err;
    }
}

$summary_body[] = "------------------------------------------";
$summary_body[] = "This report is generated by the crawler.php script.";

$summary_message = implode("\n", $summary_body);

$headers = 'From: ' . $EMAIL_FROM . "\r\n" .
    'Reply-To: ' . $EMAIL_FROM . "\r\n" .
    'X-Mailer: PHP/' . phpversion();

if (!empty($EMAIL_TO)) {
    if (empty($crawler_errors) && $ENABLE_EMAIL_CRAWLER_SUCCESS === 1) {
        @mail($EMAIL_TO, $summary_subject, $summary_message, $headers);
        log_activity("Crawler success summary email sent to: " . $EMAIL_TO);
    } elseif (!empty($crawler_errors) && $ENABLE_EMAIL_CRAWLER_ERROR === 1) {
        @mail($EMAIL_TO, $summary_subject, $summary_message, $headers);
        log_activity("Crawler error summary email sent to: " . $EMAIL_TO);
    }
}

exit(0);
