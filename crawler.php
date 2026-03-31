<?php
// crawler.php — Phase 2: Intelligent Crawling with Scoring Pipeline
// Requires: db.php, includes/functions.php, includes/scoring.php, includes/blacklist.php

declare(strict_types=1);
include 'db.php';
require_once __DIR__ . '/includes/scoring.php';
require_once __DIR__ . '/includes/blacklist.php';

// --- Lock File Management ---
$lock_file_name = 'crawler.running';
if (isset($argv[1])) {
    $arg = explode('=', $argv[1]);
    if (count($arg) === 2 && $arg[0] === '--mode' && $arg[1] === 'email_extraction') {
        $lock_file_name = 'crawler.getemails.running';
    }
}
$lock_file = __DIR__ . '/' . $lock_file_name;
file_put_contents($lock_file, date('Y-m-d H:i:s'));
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) unlink($lock_file);
});

// --- Streaming UI Functions ---
if (!function_exists('start_streaming_ui')) {
    function start_streaming_ui() {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        @ini_set('zlib.output_compression', '0');
        while (@ob_get_level() > 0) @ob_end_flush();
        @ob_implicit_flush(true);
        if (php_sapi_name() === 'cli') { force_flush(); return; }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Crawler Live</title>';
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">';
        echo '<style>body{padding:10px}.log{height:400px;white-space:pre-wrap;overflow:auto;background:#0b1220;color:#d9f99d;border:1px solid #334155;border-radius:8px;padding:10px;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace}</style>';
        echo '</head><body>';
        echo '<div class="mb-2"><b>Crawler Live — Phase 2 Intelligence</b></div><div id="l" class="log"></div>';
        echo '<script>var L=document.getElementById("l");function appendLine(t){var b=L.scrollTop+L.clientHeight>=L.scrollHeight-5;L.textContent+=t+"\\n";if(b)L.scrollTop=L.scrollHeight;}</script>';
        echo str_repeat(' ', 8192), "\n";
        force_flush();
    }
}
if (!function_exists('end_streaming_ui')) {
    function end_streaming_ui() {
        if (php_sapi_name() === 'cli') return;
        echo '</body></html>';
        force_flush();
    }
}
if (!function_exists('stream_stats_crawler')) {
    function stream_stats_crawler($domain, $pages, $emails, $t0, $extra = []) {
        $elapsed = max(0.001, microtime(true) - (float)$t0);
        $rate = sprintf('%.2f', ($pages > 0 ? $pages / $elapsed : 0.0));
        $rejected = $extra['rejected'] ?? 0;
        $budget = $extra['budget'] ?? '?';
        $quality = $extra['quality'] ?? '?';
        // Update live activity file for dashboard
        @file_put_contents(__DIR__ . '/crawl_activity.json', json_encode([
            'domain' => $domain,
            'pages' => (int)$pages,
            'budget' => $budget,
            'emails' => (int)$emails,
            'rejected' => (int)$rejected,
            'quality' => $quality,
            'elapsed' => (int)$elapsed,
            'rate' => $rate,
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        if (php_sapi_name() === 'cli') {
            echo "[STATS] domain={$domain} | pages={$pages}/{$budget} | emails={$emails} | rejected={$rejected} | quality={$quality} | {$rate} p/s" . PHP_EOL;
            force_flush();
            return;
        }
        echo "<script>(function(d,p,e,s,r,rej,bud,q){if(!window.__hud){var c=document.createElement('div');c.id='crawlHUD';c.style.cssText='position:fixed;right:10px;top:10px;background:#0b1220;color:#d9f99d;border:1px solid #334155;border-radius:8px;padding:12px 14px;font:12px/1.4 ui-monospace,Menlo,Consolas,monospace;z-index:2147483647;box-shadow:0 2px 10px rgba(0,0,0,.4);min-width:220px';c.innerHTML='<div style=\"font-weight:700;margin-bottom:6px;color:#f5a623\">Crawler v2</div><div>Domain: <span id=\"h_d\">-</span></div><div>Pages: <span id=\"h_p\">0</span>/<span id=\"h_b\">?</span></div><div>Emails: <span id=\"h_e\">0</span></div><div>Rejected: <span id=\"h_rej\">0</span></div><div>Quality: <span id=\"h_q\">?</span></div><div>Elapsed: <span id=\"h_s\">0s</span></div><div>Rate: <span id=\"h_r\">0.00</span> p/s</div>';document.body.appendChild(c);window.__hud={d:document.getElementById('h_d'),p:document.getElementById('h_p'),e:document.getElementById('h_e'),s:document.getElementById('h_s'),r:document.getElementById('h_r'),rej:document.getElementById('h_rej'),b:document.getElementById('h_b'),q:document.getElementById('h_q')};}var H=window.__hud;H.d.textContent=d;H.p.textContent=String(p);H.e.textContent=String(e);H.s.textContent=(s<60?(s+'s'):(Math.floor(s/60)+'m '+(s%60)+'s'));H.r.textContent=r;H.rej.textContent=String(rej);H.b.textContent=String(bud);H.q.textContent=String(q);})(" . json_encode((string)$domain) . "," . (int)$pages . "," . (int)$emails . "," . (int)$elapsed . "," . json_encode($rate) . "," . (int)$rejected . "," . json_encode((string)$budget) . "," . json_encode((string)$quality) . ");</script>\n";
        force_flush();
    }
}
if (!function_exists('curl_progress_echo')) {
    function curl_progress_echo($resource, $dl_total, $dl_now, $ul_total, $ul_now) {
        static $last = 0;
        $now = time();
        if ($now !== $last) {
            $kbNow = $dl_now > 0 ? round($dl_now / 1024, 1) : 0;
            stream_message("... downloading {$kbNow}KB");
            $last = $now;
        }
        return 0;
    }
}

// --- Lock Helpers ---
if (!function_exists('acquire_lock')) {
    function acquire_lock() {
        $lockFile = __DIR__ . '/crawler.lock';
        $fh = @fopen($lockFile, 'c+');
        if (!$fh) return false;
        if (!@flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); return false; }
        ftruncate($fh, 0);
        fwrite($fh, (string)getmypid());
        return $fh;
    }
}
if (!function_exists('release_lock')) {
    function release_lock($fh) {
        if (is_resource($fh)) { @flock($fh, LOCK_UN); @fclose($fh); }
    }
}

// --- URL Normalization ---
if (!function_exists('normalize_url')) {
    function normalize_url($base, $link) {
        if (preg_match('/^https?:\/\//i', $link)) return $link;
        if (substr($link, 0, 1) == '/') {
            $parsed_base = parse_url($base);
            return ($parsed_base['scheme'] ?? 'http') . '://' . ($parsed_base['host'] ?? '') . $link;
        }
        $parsed_base = parse_url($base);
        $scheme = $parsed_base['scheme'] ?? 'http';
        $host = $parsed_base['host'] ?? '';
        $path = dirname($parsed_base['path'] ?? '/');
        $path_parts = array_filter(explode('/', $path), fn($p) => $p !== '' && $p !== '.');
        foreach (explode('/', $link) as $part) {
            if ($part === '..') array_pop($path_parts);
            elseif ($part !== '' && $part !== '.') $path_parts[] = $part;
        }
        return $scheme . '://' . $host . '/' . implode('/', $path_parts);
    }
}

// --- Strip tracking/junk query params from URLs ---
if (!function_exists('clean_url')) {
    function clean_url(string $url): string {
        $parsed = parse_url($url);
        if (!isset($parsed['query'])) return strtok($url, '#');

        // Params to strip (tracking, session, analytics junk)
        $stripParams = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'referer',
            'source', 'campaign', 'affiliate', 'partner',
            'sid', 'session', 'sessionid', 'phpsessid', 'jsessionid', 'token',
            '_ga', '_gid', '_gl', 'hsCtaTracking', 'hsa_acc', 'hsa_cam',
            'share', 'shared', 'lang', 'locale', 'cb', 'cachebuster', '_t', 'timestamp',
        ];

        parse_str($parsed['query'], $params);
        foreach ($stripParams as $p) {
            unset($params[strtolower($p)]);
            unset($params[$p]);
        }
        // Also strip any param with very long values (likely session/hash tokens)
        foreach ($params as $k => $v) {
            if (is_string($v) && strlen($v) > 60) unset($params[$k]);
        }

        $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '/');
        if (!empty($params)) {
            ksort($params); // normalize param order
            $base .= '?' . http_build_query($params);
        }
        return $base;
    }
}

// --- Detect dynamic page traps (calendars, infinite pagination, etc.) ---
if (!function_exists('is_trap_url')) {
    function is_trap_url(string $url, array &$pathPatternCounts, int $maxPerPattern = 3): bool {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Calendar/date traps: /2025/01/15, /calendar/2025-01-15
        if (preg_match('/\/\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2}/', $path)) return true;

        // Infinite pagination: /page/999
        if (preg_match('/\/page\/(\d+)/i', $path, $m) && (int)$m[1] > 20) return true;

        // Query-based pagination past page 20
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            foreach (['page', 'p', 'pg', 'offset', 'start'] as $pageParam) {
                if (isset($params[$pageParam]) && (int)$params[$pageParam] > 20) return true;
            }
        }

        // Path pattern limiting: collapse numbers to {N} and limit unique URLs per pattern
        $patternPath = preg_replace('/\d+/', '{N}', $path);
        if (!isset($pathPatternCounts[$patternPath])) {
            $pathPatternCounts[$patternPath] = 0;
        }
        $pathPatternCounts[$patternPath]++;
        if ($pathPatternCounts[$patternPath] > $maxPerPattern) return true;

        return false;
    }
}

// --- cURL Fetch Helper ---
function fetch_page(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_REFERER => 'https://www.google.com/',
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_LOW_SPEED_LIMIT => 300,
        CURLOPT_LOW_SPEED_TIME => 8,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => 'curl_progress_echo',
    ]);
    $html = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['html' => $html, 'error' => $err, 'http_code' => $code];
}

// --- Log a rejection to DB ---
function log_rejection(int $domain_id, string $email, ?string $page_url, string $reason, string $category): void {
    global $conn;
    // Check if table exists (Phase 2 migration may not have run yet)
    static $tableChecked = null;
    if ($tableChecked === null) {
        $tableChecked = (bool)$conn->query("SELECT 1 FROM email_rejections LIMIT 0");
        if (!$tableChecked) return; // Table doesn't exist yet
    }
    if (!$tableChecked) return;

    $stmt = $conn->prepare("INSERT INTO email_rejections (domain_id, email, page_url, rejection_reason, rejection_category) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param('issss', $domain_id, $email, $page_url, $reason, $category);
    $stmt->execute();
    $stmt->close();
}

// --- Record crawl_pages entry ---
function record_crawl_page(int $domain_id, string $url, ?int $quality_score, int $http_status, int $extracted, int $accepted, int $rejected): void {
    global $conn;
    static $tableChecked = null;
    if ($tableChecked === null) {
        $tableChecked = (bool)$conn->query("SELECT 1 FROM crawl_pages LIMIT 0");
        if (!$tableChecked) return;
    }
    if (!$tableChecked) return;

    $stmt = $conn->prepare("INSERT INTO crawl_pages (domain_id, url, quality_score, http_status, emails_extracted, emails_accepted, emails_rejected) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param('isiiiii', $domain_id, $url, $quality_score, $http_status, $extracted, $accepted, $rejected);
    $stmt->execute();
    $stmt->close();
}

// ======= 8-Stage Pipeline: crawl_page =======

function crawl_page(string $url, string $domain, array &$visited, int &$email_count, int $domain_id, array $page_delay, int $depth, int $max_depth, array &$crawl_state): void {

    // Check stop conditions
    if ($crawl_state['stopped']) return;
    if ($depth > $max_depth) return;
    if (count($visited) >= $crawl_state['budget']) {
        $crawl_state['stopped'] = true;
        $crawl_state['stop_reason'] = 'Budget exhausted (' . $crawl_state['budget'] . ' pages)';
        log_activity($crawl_state['stop_reason']);
        return;
    }
    if ($crawl_state['consecutive_low_pages'] >= $crawl_state['max_consecutive_low']) {
        $crawl_state['stopped'] = true;
        $crawl_state['stop_reason'] = 'Too many consecutive low-quality pages (' . $crawl_state['consecutive_low_pages'] . ')';
        log_activity($crawl_state['stop_reason']);
        return;
    }
    // Stop if too many catalog/pattern emails (auto-generated product codes, not real contacts)
    if (($crawl_state['catalog_rejected'] ?? 0) >= 10) {
        $crawl_state['stopped'] = true;
        $crawl_state['stop_reason'] = 'Too many catalog/auto-generated emails (' . $crawl_state['catalog_rejected'] . ')';
        log_activity($crawl_state['stop_reason']);
        return;
    }
    $totalExtracted = $crawl_state['total_extracted'];
    if ($totalExtracted >= 5 && $crawl_state['total_rejected'] / $totalExtracted > $crawl_state['max_fake_ratio']) {
        $crawl_state['stopped'] = true;
        $crawl_state['stop_reason'] = 'High fake email ratio (' . round($crawl_state['total_rejected'] / $totalExtracted * 100) . '%)';
        log_activity($crawl_state['stop_reason']);
        return;
    }

    // === STAGE 1: Pre-filter ===
    $url = clean_url($url); // Strip tracking params, normalize query string
    if (isset($visited[$url])) return;
    // Skip non-HTML resources
    if (preg_match('/\.(jpg|jpeg|png|gif|pdf|zip|css|js|mp4|avi|mov|svg|woff|woff2|ttf|eot|ico)(\?.*)?$/i', $url)) return;
    if (stripos($url, 'cdn-cgi') !== false || stripos($url, 'mailto:') === 0) return;
    // Detect dynamic page traps (calendars, infinite pagination, path flooding)
    if (is_trap_url($url, $crawl_state['path_patterns'])) {
        log_activity("TRAP: Skipping dynamic/trap URL: $url");
        return;
    }

    $visited[$url] = true;
    $pagesCrawled = count($visited);

    // Check min pages threshold
    $minPages = get_min_pages_threshold();
    if ($minPages > 0 && $pagesCrawled >= $minPages) {
        mark_domain_crawled_once($domain_id);
    }

    log_activity("Visiting: $url (Depth: $depth, Page: $pagesCrawled/{$crawl_state['budget']})");
    stream_stats_crawler($domain, $pagesCrawled, $email_count, $GLOBALS['__crawl_t0'], [
        'rejected' => $crawl_state['total_rejected'],
        'budget' => $crawl_state['budget'],
        'quality' => $crawl_state['domain_quality'],
    ]);

    // === STAGE 2: Page scan (fetch + score) ===
    $result = fetch_page($url);

    if ($result['html'] === false || $result['http_code'] >= 400) {
        log_activity("Failed: $url (HTTP {$result['http_code']}, err: {$result['error']})");
        record_crawl_page($domain_id, $url, null, $result['http_code'], 0, 0, 0);
        update_domain_progress($domain_id, $email_count, $pagesCrawled);
        return;
    }

    $html = $result['html'];
    $decoded_html = html_entity_decode($html);

    // Score page quality
    $pageScore = score_page_quality($html, $url, $domain);
    $pageQuality = $pageScore['score'];
    $pageSignals = implode(', ', $pageScore['signals']);

    stream_message("Page quality: {$pageQuality}/100 [{$pageSignals}]");

    // === STAGE 3: Early decision ===
    if ($pageQuality < $crawl_state['page_quality_threshold']) {
        log_activity("Skipping low-quality page ({$pageQuality}/100): $url");
        stream_message("SKIP: Low quality page ({$pageQuality}/100)");
        $crawl_state['consecutive_low_pages']++;
        record_crawl_page($domain_id, $url, $pageQuality, $result['http_code'], 0, 0, 0);
        update_domain_progress($domain_id, $email_count, $pagesCrawled);
        // Still follow links from low-quality pages (they might link to good pages)
        goto follow_links;
    }

    // Reset consecutive low pages counter on a good page
    $crawl_state['consecutive_low_pages'] = 0;

    // === STAGE 4: Extraction ===
    // Strict regex: username allows letters, digits, dots, hyphens, underscores only
    // No % or + (which capture URL-encoded junk like +%bg@s.di)
    preg_match_all('/[a-zA-Z0-9._\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $decoded_html, $matches);
    $raw_emails = array_unique($matches[0]);

    $page_extracted = 0;
    $page_accepted = 0;
    $page_rejected = 0;

    global $conn;
    foreach ($raw_emails as $raw_email) {
        $email = trim(strtolower($raw_email));
        $email = trim($email, '. ');

        // === GATEKEEPER: strict format check (single source of truth) ===
        $gate = is_clean_email($email);
        if (!$gate['valid']) {
            log_activity("GATE REJECT: $email — {$gate['reason']}");
            continue;
        }

        $page_extracted++;
        $crawl_state['total_extracted']++;

        // === STAGE 5: Validation ===

        // 5a. Fake email detection
        $fakeResult = detect_fake_email($email);
        if ($fakeResult['is_fake']) {
            log_activity("REJECT (fake): $email — {$fakeResult['reason']}");
            log_rejection($domain_id, $email, $url, $fakeResult['reason'], $fakeResult['category']);
            $page_rejected++;
            $crawl_state['total_rejected']++;
            continue;
        }

        // 5b. Catalog/auto-generated email detection (e.g., sales.keg@domain, sales.kip@domain)
        $catalogResult = detect_catalog_email($email);
        if ($catalogResult['is_pattern']) {
            // Check if this pattern is flooding (more than 2 of same pattern)
            if (is_pattern_flooding($email, $crawl_state['email_patterns'], 2)) {
                log_activity("REJECT (catalog pattern): $email — {$catalogResult['reason']}");
                log_rejection($domain_id, $email, $url, $catalogResult['reason'], 'no_business_relevance');
                $page_rejected++;
                $crawl_state['total_rejected']++;
                $crawl_state['catalog_rejected'] = ($crawl_state['catalog_rejected'] ?? 0) + 1;
                continue;
            }
        }

        // 5c. TLD check
        if (!is_allowed_email_tld($email)) {
            log_activity("REJECT (TLD): $email");
            log_rejection($domain_id, $email, $url, 'Unallowed TLD', 'bad_tld');
            $page_rejected++;
            $crawl_state['total_rejected']++;
            continue;
        }

        // 5d. Confidence scoring
        $conf = score_email_confidence($email, $html, $domain, $pageQuality);

        if ($conf['score'] < $crawl_state['email_confidence_threshold']) {
            log_activity("REJECT (low confidence {$conf['score']}): $email — " . implode(', ', $conf['reasons']));
            log_rejection($domain_id, $email, $url, "Low confidence: {$conf['score']}", 'no_business_relevance');
            $page_rejected++;
            $crawl_state['total_rejected']++;
            continue;
        }

        // 5d. Duplicate check (global — across all domains)
        $escaped_email = $conn->real_escape_string($email);
        $dup_check = $conn->query("SELECT id FROM emails WHERE email = '{$escaped_email}' LIMIT 1");
        if ($dup_check && $dup_check->num_rows > 0) {
            log_rejection($domain_id, $email, $url, 'Duplicate email (global)', 'duplicate');
            $page_rejected++;
            $crawl_state['total_rejected']++;
            continue;
        }

        // === STAGE 6 & 7: Conditional API / AI (reserved for future) ===
        // Currently pass-through

        // === STAGE 8: Store ===
        $stmt = $conn->prepare("INSERT INTO emails (domain_id, name, email, confidence_score, confidence_tier, page_url, page_quality_score) VALUES (?, '', ?, ?, ?, ?, ?)");
        if ($stmt) {
            $confScore = $conf['score'];
            $confTier = $conf['tier'];
            $stmt->bind_param('issisi', $domain_id, $email, $confScore, $confTier, $url, $pageQuality);
            if ($stmt->execute()) {
                $email_count++;
                $page_accepted++;
                log_activity("ACCEPT ({$conf['tier']}, {$conf['score']}): $email");
                stream_message("Email: $email [confidence: {$conf['score']}, tier: {$conf['tier']}]");
            } else {
                // Might be a duplicate key error if unique constraint fires
                log_activity("Insert failed for $email: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    // Record page stats
    record_crawl_page($domain_id, $url, $pageQuality, $result['http_code'], $page_extracted, $page_accepted, $page_rejected);
    update_domain_progress($domain_id, $email_count, $pagesCrawled);

    stream_message("Page result: {$page_extracted} extracted, {$page_accepted} accepted, {$page_rejected} rejected");
    stream_stats_crawler($domain, $pagesCrawled, $email_count, $GLOBALS['__crawl_t0'], [
        'rejected' => $crawl_state['total_rejected'],
        'budget' => $crawl_state['budget'],
        'quality' => $crawl_state['domain_quality'],
    ]);

    follow_links:

    // Extract and follow links
    preg_match_all('/<a\s+(?:[^>]*?\s+)?href=["\']([^"\']+)["\']/i', $html, $links);

    // Prioritize contact/about pages
    $sorted_links = [];
    $contact_links = [];
    $other_links = [];
    foreach (($links[1] ?? []) as $link) {
        if (preg_match('/(contact|about|team|staff|people|leadership)/i', $link)) {
            $contact_links[] = $link;
        } else {
            $other_links[] = $link;
        }
    }
    $sorted_links = array_merge($contact_links, $other_links);

    // Page delay
    $sleepFor = rand($page_delay[0], $page_delay[1]);
    if ($sleepFor > 0) sleep($sleepFor);

    foreach ($sorted_links as $link) {
        if ($crawl_state['stopped']) return;
        if (preg_match('/\.(jpg|jpeg|png|gif|pdf|zip|css|js|mp4|svg|woff|ico)(\?.*)?$/i', $link)) continue;
        if (stripos($link, 'cdn-cgi') !== false || stripos($link, 'mailto:') === 0) continue;

        $absolute_link = clean_url(normalize_url($url, $link));
        $parsed = parse_url($absolute_link);
        if (isset($parsed['host']) && str_replace('www.', '', $parsed['host']) !== str_replace('www.', '', $domain)) continue;

        crawl_page($absolute_link, $domain, $visited, $email_count, $domain_id, $page_delay, $depth + 1, $max_depth, $crawl_state);
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

// Load settings
$page_delay = [
    max(0, (int)(get_setting_value('page_delay_min') ?? 1)),
    max(0, (int)(get_setting_value('page_delay_max') ?? 3)),
];
if ($page_delay[1] < $page_delay[0]) $page_delay[1] = $page_delay[0];

$domain_delay = [
    max(0, (int)(get_setting_value('domain_delay_min') ?? 1)),
    max(0, (int)(get_setting_value('domain_delay_max') ?? 2)),
];
if ($domain_delay[1] < $domain_delay[0]) $domain_delay[1] = $domain_delay[0];

$max_depth = max(0, (int)(get_setting_value('max_depth') ?? 20));
$domain_quality_enabled = (int)(get_setting_value('domain_quality_enabled') ?? 1);
$page_quality_threshold = (int)(get_setting_value('page_quality_threshold') ?? 30);
$email_confidence_threshold = (int)(get_setting_value('email_confidence_threshold') ?? 40);
$max_fake_ratio = (float)(get_setting_value('max_fake_ratio') ?? 0.60);
$max_consecutive_low = (int)(get_setting_value('max_consecutive_low_pages') ?? 3);

$EMAIL_TO = get_setting_value('email_to') ?? '';
$EMAIL_FROM = get_setting_value('email_from') ?? '';
$EMAIL_SUBJ_PREFIX = get_setting_value('email_subj_prefix') ?? 'Crawler Report';
$ENABLE_EMAIL_CRAWLER_SUCCESS = (int)(get_setting_value('enable_email_crawler_success') ?? 1);
$ENABLE_EMAIL_CRAWLER_ERROR = (int)(get_setting_value('enable_email_crawler_error') ?? 1);

$crawler_errors = [];

if (!ensure_db_connection()) {
    stream_message("FATAL: No database connection. Exiting.");
    release_lock($__lock);
    end_streaming_ui();
    exit(1);
}

// Check for --domain=ID argument (crawl a specific domain)
$forced_domain_id = 0;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--domain=(\d+)$/', $arg, $m)) {
        $forced_domain_id = (int)$m[1];
    }
}

// Select domain to crawl
$has_blacklisted = (bool)$conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'blacklisted'");

if ($forced_domain_id > 0) {
    // Crawl a specific domain by ID (regardless of crawled status — re-crawl it)
    $domain_query = "SELECT * FROM domains WHERE id = {$forced_domain_id} LIMIT 1";
    stream_message("Forced crawl for domain ID: {$forced_domain_id}");
} else {
    // Select next domain in queue (skip blacklisted, prioritize by quality + priority)
    $domain_query = "SELECT * FROM domains WHERE crawled = 0 AND donot = 0";
    if ($has_blacklisted) {
        $domain_query .= " AND (blacklisted = 0 OR blacklisted IS NULL)";
        $domain_query .= " ORDER BY priority DESC, COALESCE(quality_score, 50) DESC, id ASC LIMIT 1";
    } else {
        $domain_query .= " ORDER BY priority DESC, id ASC LIMIT 1";
    }
}

$res = $conn->query($domain_query);
if ($res === false) {
    stream_message("DB Error: " . $conn->error);
    release_lock($__lock);
    end_streaming_ui();
    exit(1);
}

$domains_processed_count = 0;
$total_emails_found_in_run = 0;

if ($row = $res->fetch_assoc()) {
    $domains_processed_count++;
    $domain_id = (int)$row['id'];
    $domain_to_crawl = $row['domain'];
    $start_url = preg_match('/^https?:\/\//i', $domain_to_crawl) ? $domain_to_crawl : "https://$domain_to_crawl";
    $host_domain = parse_url($start_url, PHP_URL_HOST);

    $GLOBALS['__crawl_t0'] = microtime(true);

    // Write current crawl activity for dashboard display
    @file_put_contents(__DIR__ . '/crawl_activity.json', json_encode([
        'domain' => $host_domain,
        'domain_id' => $domain_id,
        'started_at' => date('Y-m-d H:i:s'),
        'pages' => 0,
        'emails' => 0,
    ]));

    log_activity("=== START Crawl: $host_domain ===");
    stream_message("=== START Crawl: $host_domain ===");

    // --- Domain Quality Assessment ---
    $domain_budget = $max_depth; // default
    $domain_quality_tier = 'medium';
    $domain_quality_score = 50;

    if ($domain_quality_enabled) {
        stream_message("Assessing domain quality...");
        $homepageResult = fetch_page($start_url);

        if ($homepageResult['html'] !== false && $homepageResult['http_code'] < 400) {
            $dq = score_domain_quality($host_domain, $homepageResult['html']);
            $domain_quality_score = $dq['score'];
            $domain_quality_tier = $dq['tier'];
            $domain_budget = $dq['budget'];

            stream_message("Domain quality: {$domain_quality_score}/100 ({$domain_quality_tier}) — budget: {$domain_budget} pages");
            log_activity("Domain quality: {$domain_quality_score}/100 ({$domain_quality_tier}), budget: {$domain_budget}, signals: " . implode(', ', $dq['signals']));

            // Update domain record with quality data
            if ($has_blacklisted) {
                $stmt = $conn->prepare("UPDATE domains SET quality_score = ?, quality_tier = ?, max_pages_budget = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('isii', $domain_quality_score, $domain_quality_tier, $domain_budget, $domain_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // Skip spam domains entirely
            if ($domain_quality_tier === 'spam') {
                stream_message("SPAM domain detected — skipping entirely.");
                log_activity("Domain {$host_domain} classified as spam, skipping.");
                $stmt = $conn->prepare("UPDATE domains SET crawled = 1, date_crawled = NOW(), emails_found = 0, urls_crawled = 0 WHERE id = ?");
                if ($stmt) { $stmt->bind_param('i', $domain_id); $stmt->execute(); $stmt->close(); }
                if ($has_blacklisted) {
                    blacklist_domain($domain_id, 'Spam domain (quality score: ' . $domain_quality_score . ')');
                }
                release_lock($__lock);
                end_streaming_ui();
                exit(0);
            }
        } else {
            stream_message("Could not fetch homepage — using default budget.");
            $domain_budget = 2; // conservative for unreachable domains
        }
    }

    // Initialize crawl state for stop conditions
    $crawl_state = [
        'budget' => max(1, $domain_budget),
        'domain_quality' => $domain_quality_tier,
        'page_quality_threshold' => $page_quality_threshold,
        'email_confidence_threshold' => $email_confidence_threshold,
        'max_fake_ratio' => $max_fake_ratio,
        'max_consecutive_low' => $max_consecutive_low,
        'consecutive_low_pages' => 0,
        'total_extracted' => 0,
        'total_rejected' => 0,
        'catalog_rejected' => 0,
        'email_patterns' => [],    // tracks catalog email pattern flooding
        'path_patterns' => [],     // tracks URL path patterns for trap detection
        'stopped' => false,
        'stop_reason' => null,
    ];

    // Start crawl metrics
    $metrics_id = null;
    $has_metrics = (bool)$conn->query("SELECT 1 FROM crawl_metrics LIMIT 0");
    if ($has_metrics) {
        $stmt = $conn->prepare("INSERT INTO crawl_metrics (domain_id) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param('i', $domain_id);
            $stmt->execute();
            $metrics_id = $stmt->insert_id;
            $stmt->close();
        }
    }

    stream_stats_crawler($host_domain, 0, 0, $GLOBALS['__crawl_t0'], [
        'rejected' => 0,
        'budget' => $crawl_state['budget'],
        'quality' => $domain_quality_tier,
    ]);

    $visited = [];
    $email_count = 0;
    crawl_page($start_url, $host_domain, $visited, $email_count, $domain_id, $page_delay, 0, $max_depth, $crawl_state);

    $urls_crawled_count = count($visited);
    $total_emails_found_in_run += $email_count;

    // Update domain as crawled
    $stmt = $conn->prepare("UPDATE domains SET crawled = 1, date_crawled = NOW(), emails_found = ?, urls_crawled = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('iii', $email_count, $urls_crawled_count, $domain_id);
        $stmt->execute();
        $stmt->close();
    }

    // Update domain scoring stats
    if ($has_blacklisted) {
        $stmt = $conn->prepare("UPDATE domains SET total_valid_emails = ?, total_rejected_emails = ? WHERE id = ?");
        if ($stmt) {
            $rejected = $crawl_state['total_rejected'];
            $stmt->bind_param('iii', $email_count, $rejected, $domain_id);
            $stmt->execute();
            $stmt->close();
        }

        // Auto-blacklist: 0 valid emails + low quality
        if ($email_count === 0 && $domain_quality_score < 20) {
            blacklist_domain($domain_id, 'Zero valid emails, low quality (' . $domain_quality_score . ')');
        }
    }

    // Finalize crawl metrics
    if ($has_metrics && $metrics_id) {
        $elapsed = (int)(microtime(true) - $GLOBALS['__crawl_t0']);
        $pagesPerEmail = $email_count > 0 ? round($urls_crawled_count / $email_count, 2) : null;
        $stopReason = $crawl_state['stop_reason'] ?? 'completed';
        $rejected = $crawl_state['total_rejected'];
        $stmt = $conn->prepare("UPDATE crawl_metrics SET run_ended_at = NOW(), pages_crawled = ?, valid_emails = ?, rejected_emails = ?, pages_per_valid_email = ?, total_time_seconds = ?, stop_reason = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('iiidisi', $urls_crawled_count, $email_count, $rejected, $pagesPerEmail, $elapsed, $stopReason, $metrics_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Clear crawl activity file
    @unlink(__DIR__ . '/crawl_activity.json');

    $stop_info = $crawl_state['stop_reason'] ? " (Stopped: {$crawl_state['stop_reason']})" : '';
    log_activity("=== END Crawl: $host_domain | Emails: $email_count | Rejected: {$crawl_state['total_rejected']} | Pages: $urls_crawled_count{$stop_info} ===");
    stream_message("=== END Crawl: $host_domain | Emails: $email_count | Rejected: {$crawl_state['total_rejected']} | Pages: $urls_crawled_count{$stop_info} ===");
    stream_stats_crawler($host_domain, $urls_crawled_count, $email_count, $GLOBALS['__crawl_t0'], [
        'rejected' => $crawl_state['total_rejected'],
        'budget' => $crawl_state['budget'],
        'quality' => $domain_quality_tier,
    ]);

    // Domain delay
    $domainSleepSeconds = rand($domain_delay[0] * 60, $domain_delay[1] * 60);
    if ($domainSleepSeconds > 0) {
        stream_message("Waiting {$domainSleepSeconds}s before next domain.");
        sleep($domainSleepSeconds);
    }
} else {
    stream_message("No domains found for crawling. Exiting.");
}

release_lock($__lock);
end_streaming_ui();

// Per-run emails disabled — use daily_crawler_report.php for consolidated daily summaries.
// Only send immediate email for critical errors that need urgent attention.
if (!empty($crawler_errors) && !empty($EMAIL_TO) && $ENABLE_EMAIL_CRAWLER_ERROR === 1) {
    $summary_subject = $EMAIL_SUBJ_PREFIX . ' - Crawler ERROR (Domains: ' . $domains_processed_count . ')';
    $summary_body = [];
    $summary_body[] = "Crawler Error Alert (" . date('Y-m-d H:i:s') . ")";
    $summary_body[] = "------------------------------------------";
    $summary_body[] = "Domains Processed: " . $domains_processed_count;
    $summary_body[] = "Errors: " . count($crawler_errors);
    $summary_body[] = "\n--- Errors ---";
    foreach (array_slice($crawler_errors, -10) as $err) $summary_body[] = "- " . $err;
    if (count($crawler_errors) > 10) $summary_body[] = "... and " . (count($crawler_errors) - 10) . " more.";
    $headers = 'From: ' . $EMAIL_FROM . "\r\n" . 'Reply-To: ' . $EMAIL_FROM . "\r\n" . 'X-Mailer: PHP/' . phpversion();
    @mail($EMAIL_TO, $summary_subject, implode("\n", $summary_body), $headers);
}

exit(0);
