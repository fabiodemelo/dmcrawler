<?php
declare(strict_types=1);
include 'db.php';

// --- START LOCK FILE MANAGEMENT ---
$lock_file = __DIR__ . '/getURLS.running';

// Create lock file at the very beginning of the script execution, storing start time
file_put_contents($lock_file, date('Y-m-d H:i:s'));

// Register a shutdown function to ensure the lock file is removed even if the script crashes
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
});
// --- END LOCK FILE MANAGEMENT ---

// Helper to read a single setting field safely
if (!function_exists('get_setting_value')) {
    function get_setting_value($field) {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) return null;
        global $conn;
        // Ensure connection is still good or re-establish
        if (!($conn instanceof mysqli) || !$conn->ping()) {
            // Attempt to reconnect or log failure
            // For now, assume global $conn is handled by db.php include
            // In a more robust system, this would trigger a reconnection.
            log_line("get_setting_value: DB connection lost or not initialized for field '{$field}'.");
            return null;
        }

        $res = $conn->query("SELECT `{$field}` AS v FROM settings WHERE id = 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return $row['v'];
        }
        return null;
    }
}

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

/**
 * Logs a URL event (inserted or skipped) and adds it to the global printedUrls array.
 * @param string $engine The name of the search engine.
 * @param string $location The location searched.
 * @param int $rank The rank of the result.
 * @param string $title The title of the search result.
 * @param string $url The normalized URL.
 * @param string $domain The base domain of the URL.
 * @param bool $inserted True if the domain was newly inserted, false if skipped.
 * @return void
 */
function print_url(string $engine, string $location, int $rank, string $title, string $url, string $domain, bool $inserted): void {
    global $printedUrls;
    $status = $inserted ? '[INSERTED]' : '[SKIPPED]';
    $line = sprintf("%s %s / %s (Rank %d) Title: %s, URL: %s, Domain: %s", $status, $engine, $location, $rank, $title, $url, $domain);
    $printedUrls[] = $line; // Add to the global array for summary email
    stream_message($line); // Output to console/browser using existing stream_message helper
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
require_once __DIR__ . '/db.php';

// ================== CONFIGURATION ==================

// SerpAPI API keys (round-robin)
// Store your SerpAPI keys here. The script will rotate through them for them for each request.
$SERPAPI_KEYS = [
    '1a59c632d23f16317ccc4a798b7aa0dde378e98f5843a1818753c12706d4646f', // fabio@demelos.com
    //'65530219a8c233d1b1449027ae10220de381c04086a4be523e40f1c9105fc8b5',  // fabio@altapro.com
    // Add more keys as needed
];

// Search parameters - now fetched from settings table
$RESULTS_PER_LOCATION = (int)(get_setting_value('results_per_location') ?? 50); // Target: Number of *new* domains to insert per engine/keyword/location combination
$MAX_PAGES = (int)(get_setting_value('max_pages') ?? 20); // Safety limit: Max pages to query from SerpAPI for any given engine/keyword/location
$OVERFETCH_FACTOR = (int)(get_setting_value('over_fetch_factor') ?? 5); // Multiplier for 'num' parameter in SerpAPI to get more results per page, hoping for more unique domains
$OUT_DIR = __DIR__ . '/output'; // The output directory is hardcoded as per previous definitions, assuming the setting would only affect the subdirectory. If 'out_dir' in settings was e.g. 'output_custom', then it would be __DIR__ . '/output_custom'. Given the prompt, it will remain as is for now.

// Local SerpAPI response cache settings
$CACHE_DIR = __DIR__ . '/cache/serp';
$CACHE_TTL = 21600; // 6 hours in seconds - How long to cache SerpAPI JSON responses

// Global domains to exclude from search results (e.g., social media, directories, personal blogs)
// These domains are generally not relevant for lead generation.
$EXCLUDE_DOMAINS = [
    '.blogspot.com', '.wordpress.com', '.wix.com', '.squarespace.com',
    '.yelp.com', '.facebook.com', '.linkedin.com', '.instagram.com',
    '.google.com', '.maps.google.com', '.yellowpages.com', '.bbb.org',
    '.angi.com', '.homeadvisor.com', '.thumbtack.com', '.manta.com', '.houzz.com'
];
$EXCLUDE_TLDS = ['.gov', '.edu', '.mil', '.org']; // Top-Level Domains to exclude

// ================== INTERNAL STATE & GLOBALS ==================
$errors = [];          // Stores error messages encountered during the run
$printedUrls = [];     // Stores log lines for URLs processed (inserted or skipped)
$logLines = [];        // Stores general progress messages
$apiKeyIndex = 0;      // Counter for round-robin API key rotation
$insertedDomains = []; // Collects domains that were successfully inserted in this run

// =============== ERROR HANDLING (Must be defined early) ===============
function report_error(string $msg): void {
    global $errors;
    $errors[] = $msg;
    log_line("ERROR: " . $msg);
}


// ================== UTILITY FUNCTIONS (Logging, Directory Management) ==================
function log_line(string $line): void {
    global $logLines;
    $logLines[] = $line;
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line) . "<br>\n";
    }
    @ob_flush(); @flush();
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Failed to create directory: $dir");
    }
}

// ================== SEARCH QUERY BUILDING ==================
/**
 * Constructs a search query string based on the engine, keyword, and location.
 * Tailors the query for different search engines to optimize results and avoid errors.
 *
 * @param string $engine The name of the search engine (e.g., 'google', 'yahoo', 'google_maps').
 * @param string $keyword The business type or search term (e.g., 'Factories').
 * @param string $location The geographic keyword (e.g., 'Utah', 'Los Angeles').
 * @return string The constructed query string. Returns an empty string if keyword or location is empty.
 */
function buildQuery(string $engine, string $keyword, string $location): string {
    // Ensure both keyword and location are not empty to avoid creating an invalid query.
    if (empty($keyword) || empty($location)) {
        return '';
    }

    // These sites are generally not desired in organic web search results for business lead generation.
    $common_neg_sites = [
        'yelp.com', 'facebook.com', 'linkedin.com', 'instagram.com',
        'google.com', 'maps.google.com', 'yellowpages.com', 'bbb.org',
        'angi.com', 'homeadvisor.com', 'thumbtack.com', 'manta.com', 'houzz.com'
    ];

    // Formulate the exclusion part of the query (e.g., "-site:yelp.com")
    $exclude_query_part = '';
    foreach ($common_neg_sites as $site) {
        $exclude_query_part .= " -site:{$site}";
    }

    // Combine keyword and location for the main query
    // Example: "Los Angeles" "Factories"
    $full_query_term = "\"{$location}\" \"{$keyword}\"";


    if ($engine === 'yahoo') {
        // Yahoo's query parser can be more sensitive to very long or complex queries.
        return sprintf('%s%s', $full_query_term, $exclude_query_part);
    } elseif ($engine === 'google_maps') {
        // The 'google_maps' engine typically takes a simpler query, often just the business type and location.
        // It does not use 'site:' operators in the same way as organic web search.
        // For Google Maps, "keyword in location" is a common format.
        return sprintf('%s in %s', $keyword, $location);
    } else {
        // For general web search engines like Google and Bing, use a broad search for '.com' domains
        // and apply the full list of site exclusions.
        return sprintf('%s site:*.com%s', $full_query_term, $exclude_query_part);
    }
}

// ================== SERPAPI INTERACTION ==================
/**
 * Rotates through the configured SerpAPI keys to distribute usage.
 * @return string A SerpAPI key.
 * @throws RuntimeException If no valid API keys are configured.
 */
function next_api_key(): string {
    global $SERPAPI_KEYS, $apiKeyIndex;
    $keys = array_values(array_filter($SERPAPI_KEYS, function ($k) { return is_string($k) && strlen(trim($k)) >= 10; }));
    if (empty($keys)) {
        throw new RuntimeException("No valid SerpAPI keys configured.");
    }
    $key = $keys[$apiKeyIndex % count($keys)];
    $apiKeyIndex++;
    return $key;
}

/**
 * Generates a unique cache key for a SerpAPI request based on its parameters.
 * @param string $engine The SerpAPI engine name (e.g., 'google', 'bing').
 * @param string $query The search query string.
 * @param int $num Number of results parameter.
 * @param int $page Page number parameter.
 * @return string A unique hash for caching.
 */
function serpapi_cache_key(string $engine, string $query, int $num, int $page): string {
    $hash = md5($engine . '|' . $query . '|' . $num . '|' . $page);
    return $hash . '.json';
}

/**
 * Retrieves a cached SerpAPI response if available and not expired.
 * @param string $engine The SerpAPI engine name.
 * @param string $query The search query string.
 * @param int $num Number of results parameter.
 * @param int $page Page number parameter.
 * @return array|null The cached JSON response as an array, or null if not found/expired.
 */
function serpapi_cache_get(string $engine, string $query, int $num, int $page): ?array {
    global $CACHE_DIR, $CACHE_TTL;
    $path = rtrim($CACHE_DIR, '/\\') . '/' . serpapi_cache_key($engine, $query, $num, $page);
    if (!is_file($path)) return null;
    $age = time() - filemtime($path);
    if ($age > $CACHE_TTL) return null; // Cache expired
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

/**
 * Saves a SerpAPI response to the local cache.
 * @param string $engine The SerpAPI engine name.
 * @param string $query The search query string.
 * @param int $num Number of results parameter.
 * @param int $page Page number parameter.
 * @param array $json The JSON response to cache.
 */
function serpapi_cache_set(string $engine, string $query, int $num, int $page, array $json): void {
    global $CACHE_DIR;
    ensure_dir($CACHE_DIR);
    $path = rtrim($CACHE_DIR, '/\\') . '/' . serpapi_cache_key($engine, $query, $num, $page);
    @file_put_contents($path, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Performs a generic HTTP GET request to fetch JSON data.
 * @param string $url The URL to request.
 * @param int $timeoutSec The timeout for the request in seconds.
 * @return array The JSON response as an associative array.
 * @throws RuntimeException If the HTTP request fails or returns a non-2xx status, or if JSON is invalid.
 */
function http_get_json(string $url, int $timeoutSec = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'BusinessURLFetcher/1.3 (+PHP cURL)',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP request failed: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP $code for URL: $url");
    }
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException("Invalid JSON response.");
    return $json;
}

/**
 * Fetches search results for a single page from SerpAPI, utilizing caching and API key rotation.
 * @param string $engine The SerpAPI engine name.
 * @param string $query The search query.
 * @param int $num Number of results to request (note: SerpAPI has its own limits per page).
 * @param int $page The page number for the search results.
 * @return array The parsed JSON response from SerpAPI.
 * @throws InvalidArgumentException If the search query is empty.
 * @throws RuntimeException On API communication or response errors.
 */
function serpapi_search_page(string $engine, string $query, int $num, int $page = 1): array {
    // Ensure query is a string and not empty to prevent errors
    if (empty($query)) {
        throw new InvalidArgumentException("Search query cannot be empty for engine '{$engine}'.");
    }

    // Try cache first to avoid spending credits and time
    $cached = serpapi_cache_get($engine, $query, $num, $page);
    if (is_array($cached)) {
        log_line("Using cached results for $engine / Query: '$query' / Page: $page");
        return $cached;
    }

    $apiKey = next_api_key(); // Rotate API keys per request
    $params = [
        'engine' => $engine,
        'q'      => $query,
        'num'    => max(10, min(100, $num)), // Request between 10 and 100 results if 'num' is used
        'api_key'=> $apiKey,
        'safe'   => 'active', // Safe search on
    ];

    // Determine the correct pagination parameter based on the engine
    if ($engine === 'google_maps' || $engine === 'yahoo') {
        $params['p'] = max(1, $page); // Use 'p' for page number for google_maps and yahoo
    } else {
        $params['page'] = max(1, $page); // Use 'page' for other engines
    }

    // Special handling for 'google_maps' engine parameters as it differs from standard web search.
    if ($engine === 'google_maps') {
        $params['type'] = 'search'; // Specify search type for Google Maps (e.g., 'search', 'place', 'reviews')
        $params['q'] = $query;      // The query for Google Maps is often simpler
        unset($params['num']); // Google Maps engine typically handles 'num' differently or less directly
        // Additional Google Maps parameters (like 'll' for latitude/longitude, 'hl' for language, 'gl' for country)
        // could be added here if more specific location targeting is needed.
    }

    $url = 'https://serpapi.com/search.json?' . http_build_query($params);
    $json = http_get_json($url);

    // Save successful API response to cache
    serpapi_cache_set($engine, $query, $num, $page, $json);
    return $json;
}

/**
 * Extracts relevant URL and title data from raw SerpAPI JSON response.
 * Handles different response structures for various engines (e.g., organic_results vs local_results for Google Maps).
 * @param array $json The raw JSON response from SerpAPI.
 * @return array An array of associative arrays, each containing 'title' and 'url'.
 */
function parse_serp_results(array $json): array {
    $out = [];

    // Prioritize 'local_results' for engines like 'google_maps'
    if (isset($json['local_results'])) {
        foreach ($json['local_results'] as $r) {
            $link = $r['website'] ?? ($r['link'] ?? null); // 'website' is common in local results, fallback to 'link'
            if (!$link) continue;
            $title = $r['title'] ?? '';
            $out[] = ['title' => $title, 'url' => $link];
        }
    }
    // Fallback to 'organic_results' for standard web search engines
    elseif (isset($json['organic_results'])) {
        foreach ($json['organic_results'] as $r) {
            $link  = $r['link'] ?? ($r['url'] ?? null); // 'link' or 'url' might contain the target URL
            if (!$link) continue;
            $title = $r['title'] ?? '';
            $out[] = ['title' => $title, 'url' => $link];
        }
    }
    // You might need to add more elseif conditions here for other specific SerpAPI engines
    // if their result structure differs significantly (e.g., 'ads', 'shopping_results').

    return $out;
}

// ================== URL PROCESSING & FILTERING ==================
/**
 * Normalizes a URL to a consistent format (scheme, host without www, first path segment).
 * This helps in identifying unique domains and comparing them.
 * @return string|null The normalized URL, or null if parsing fails.
 */
function normalize_url(string $url): ?string {
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) return null; // Invalid URL or no host
    $scheme = $parts['scheme'] ?? 'https'; // Default to https if no scheme specified
    $host = strtolower($parts['host']);
    $host = preg_replace('/^www\./i', '', $host); // Remove 'www.' for consistency
    $path = $parts['path'] ?? '/';
    // Take only the first segment of the path for broader domain grouping, ignore query strings/fragments
    $firstSeg = explode('/', trim($path, '/'))[0] ?? '';
    $pathNorm = $firstSeg ? '/' . $firstSeg : '/';
    return sprintf('%s://%s%s', $scheme, $host, $pathNorm);
}

/**
 * Extracts the base domain from a URL (e.g., "example.com" from "http://www.example.com/path").
 * @return string|null The base domain, or null if parsing fails.
 */
function domain_only(string $url): ?string {
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) return null;
    return strtolower(preg_replace('/^www\./i', '', $parts['host'])); // Remove 'www.' for base domain
}

/**
 * Checks if a given URL's domain should be excluded based on predefined lists.
 * This prevents processing unwanted domains (e.g., social media, internal Google links).
 * @param string $url The URL to check.
 * @param array $excludeDomains List of domain substrings to exclude.
 * @param array $excludeTlds List of TLDs to exclude.
 * @return bool True if the URL should be excluded, false otherwise.
 */
function should_exclude(string $url, array $excludeDomains, array $excludeTlds): bool {
    $host = domain_only($url);
    if (!$host) return true; // Exclude if domain couldn't be parsed

    foreach ($excludeDomains as $bad) {
        if (str_ends_with($host, $bad)) return true;
    }
    foreach ($excludeTlds as $tld) {
        if (str_ends_with($host, $tld)) return true;
    }
    // Exclude specific types of Google results that aren't actual business sites
    if (str_contains($url, 'webcache.googleusercontent.com') || str_contains($url, 'translate.google')) return true;

    return false;
}

// ================== DATABASE INTERACTION ==================
/**
 * Provides a PDO database connection. Uses a singleton pattern to ensure only one connection.
 * @return PDO The PDO database connection object.
 * @throws PDOException On connection errors.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    global $host, $db, $user, $pass; // Assumes these variables are set by db.php
    $dsn = "mysql:host={$host};dbname={$db};charset=latin1";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Fetch rows as associative arrays by default
    ]);
    return $pdo;
}

/**
 * Loads all existing domains from the `domains` table into a hash map for efficient lookup.
 * This avoids repeated database queries for each URL found.
 * @return array A map where keys are normalized domain names and values are true.
 */
function load_existing_domains(): array {
    $map = [];
    $stmt = db()->query("SELECT `domain` FROM `domains`");
    while ($row = $stmt->fetch()) {
        $d = strtolower(trim($row['domain']));
        if ($d !== '') $map[$d] = true;
    }
    return $map;
}

/**
 * Inserts a new unique domain into the `domains` table.
 * @param string $domain The domain name to insert.
 * @return bool True on successful insertion.
 * @throws PDOException On database errors.
 */
function db_insert_domain(string $domain): bool {
    $sql = "INSERT INTO `domains` (`id`, `domain`, `crawled`, `date_added`, `date_crawled`, `emails_found`, `urls_crawled`)
            VALUES (NULL, :d, 0, CURRENT_TIMESTAMP, NULL, 0, 0)";
    $stmt = db()->prepare($sql);
    $stmt->execute([':d' => $domain]);
    return true;
}

/**
 * Fetches active keywords (status = 1) from the `keywords` table.
 * @return array An array of associative arrays, each containing 'id' and 'keyword'.
 */
function fetch_active_keywords(): array {
    $keywords = [];
    try {
        // Fetch both id and keyword
        $stmt = db()->query("SELECT `id`, `keyword` FROM `keywords` WHERE `status` = 1");
        while ($row = $stmt->fetch()) {
            $keywords[] = $row; // Store as associative array { 'id': 1, 'keyword': 'Utah' }
        }
        return $keywords; // Always return an array
    } catch (Throwable $e) {
        report_error("Failed to fetch active keywords from DB: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Fetches active locations (status = 1) from the `locations` table.
 * @return array An array of associative arrays, each containing 'id' and 'name'.
 */
function fetch_active_locations(): array {
    $locations = [];
    try {
        $stmt = db()->query("SELECT `id`, `name` FROM `locations` WHERE `status` = 1");
        while ($row = $stmt->fetch()) {
            $locations[] = $row;
        }
        return $locations; // Always return an array
    } catch (Throwable $e) {
        report_error("Failed to fetch active locations from DB: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Fetches active engines (status = 1) from the `engines` table.
 * @return array An array of associative arrays, each containing 'ID' and 'name'.
 */
function fetch_active_engines(): array {
    $engines = [];
    try {
        // Fetch both ID and name (corrected 'ID' to match schema)
        $stmt = db()->query("SELECT `ID`, `name` FROM `engines` WHERE `status` = 1");
        while ($row = $stmt->fetch()) {
            $engines[] = $row; // Store as associative array { 'ID': 1, 'name': 'google' }
        }
        return $engines; // Always return an array
    } catch (Throwable $e) {
        report_error("Failed to fetch active engines from DB: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Retrieves the SERP cooldown period from the settings table.
 * @return int Cooldown period in hours, defaults to 24 if not found or invalid.
 */
function get_serp_cooldown_hours(): int {
    static $cooldown = null;
    if ($cooldown !== null) {
        return $cooldown;
    }
    $value = get_setting_value('serp_cooldown_hours');
    $cooldown = (int)($value ?? 24); // Default to 24 hours
    if ($cooldown < 0) {
        $cooldown = 0;
    }
    return $cooldown;
}

/**
 * Checks the last search time for a given keyword-engine-location triplet.
 *
 * @param int $keywordId The ID of the keyword.
 * @param int $engineId The ID of the engine.
 * @param int $locationId The ID of the location.
 * @return DateTime|null The DateTime object of the last search, or null if never searched.
 */
function get_keyword_engine_search_status(int $keywordId, int $engineId, int $locationId): ?DateTime {
    try {
        $stmt = db()->prepare("SELECT `last_searched_at` FROM `keyword_engine_search_log` WHERE `keyword_id` = :kid AND `engine_id` = :eid AND `location_id` = :lid LIMIT 1");
        $stmt->execute([':kid' => $keywordId, ':eid' => $engineId, ':lid' => $locationId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['last_searched_at'])) {
            return new DateTime($row['last_searched_at']);
        }
    } catch (Throwable $e) {
        report_error("DB Error fetching search log for keyword {$keywordId} / engine {$engineId} / location {$locationId}: " . $e->getMessage());
    }
    return null;
}

/**
 * Updates or inserts the search log for a specific keyword-engine-location triplet.
 *
 * @param int $keywordId The ID of the keyword.
 * @param int $engineId The ID of the engine.
 * @param int $locationId The ID of the location.
 * @param string $statusCode The status of the search (e.g., 'completed_ok', 'failed_api').
 * @param string|null $notes Optional notes for the log entry.
 * @return void
 */
function update_keyword_engine_search_status(int $keywordId, int $engineId, int $locationId, string $statusCode, ?string $notes = null): void {
    try {
        $stmt = db()->prepare("
            INSERT INTO `keyword_engine_search_log` (`keyword_id`, `engine_id`, `location_id`, `last_searched_at`, `status_code`, `notes`)
            VALUES (:kid, :eid, :lid, NOW(), :status, :notes)
            ON DUPLICATE KEY UPDATE `last_searched_at` = NOW(), `status_code` = :status, `notes` = :notes
        ");
        $stmt->execute([
            ':kid' => $keywordId,
            ':eid' => $engineId,
            ':lid' => $locationId,
            ':status' => $statusCode,
            ':notes' => $notes
        ]);
    } catch (Throwable $e) {
        report_error("DB Error updating search log for keyword {$keywordId} / engine {$engineId} / location {$locationId}: " . $e->getMessage());
    }
}


// =============== CSV REPORTING SETUP ==================
$date = (new DateTime('now'))->format('Y-m-d');
ensure_dir($OUT_DIR); // Ensure output directory exists

$outCsv = sprintf('%s/business_urls_%s.csv', $OUT_DIR, $date);
$fp = @fopen($outCsv, 'a'); // Use 'a' for append mode if script is run multiple times in a day
if ($fp) {
    // Only write header if the file is new or empty
    if (filesize($outCsv) == 0) {
        fputcsv($fp, ['date', 'engine', 'location', 'rank', 'title', 'url', 'domain', 'inserted_to_db']);
    }
} else {
    report_error("Unable to write to $outCsv. Check permissions.");
}

// ================== MAIN EXECUTION LOGIC ==================
$totalInserted = 0; // Tracks total new domains inserted across all searches in this run

// Preload existing domains into memory for faster duplicate checking
try {
    $existingDomains = load_existing_domains(); // ['domain.com' => true]
} catch (Throwable $e) {
    report_error("Failed to preload existing domains: " . $e->getMessage());
    $existingDomains = []; // Ensure it's an array even if loading fails
}

// Fetch active keywords from the database
$KEYWORDS = fetch_active_keywords();
if (empty($KEYWORDS)) {
    report_error("No active keywords found in the database. Please add keywords with status = 1.");
    exit(1); // Exit if nothing to search for
}

// Fetch active locations from the database
$LOCATIONS = fetch_active_locations();
if (empty($LOCATIONS)) {
    report_error("No active locations found in the database. Please add locations with status = 1.");
    exit(1); // Exit if no locations to search for
}


// Fetch active engines from the database
$ENGINES = fetch_active_engines();
if (empty($ENGINES)) {
    report_error("No active engines found in the database. Please add engines with status = 1.");
    exit(1); // Exit if no engines to search with
}

// Retrieve the configured cooldown period
$SERP_COOLDOWN_HOURS = get_serp_cooldown_hours();

// Initialize API key rotation counter
try {
    next_api_key(); // Warm up the API key rotation
} catch (Throwable $e) {
    report_error("API key configuration error: " . $e->getMessage());
    exit(1);
}

// --- Main Search Loop ---
// Iterates through each active engine, then each keyword, then each location.
// This ensures that each engine searches for each keyword in each specified location.
foreach ($ENGINES as $engine_data) { // Loop through active engines from DB
    $engine_id = $engine_data['ID'];
    $engine_name = $engine_data['name'];

    foreach ($KEYWORDS as $keyword_data) { // Loop through active keywords
        $keyword_id = $keyword_data['id'];
        $keyword_name = $keyword_data['keyword'];

        foreach ($LOCATIONS as $location_data) { // Loop through active locations
            $location_id = $location_data['id']; // Corrected to 'id'
            $location_name = $location_data['name']; // Using 'name' for location name as per your query for 'locations' table

            // Trim names to ensure no unexpected whitespace causes issues
            $engine_name = trim($engine_name);
            $keyword_name = trim($keyword_name);
            $location_name = trim($location_name);

            // Check for empty names early before proceeding with search logic
            if (empty($engine_name)) {
                report_error("Skipping: Empty engine name for ID {$engine_id}.");
                continue;
            }
            if (empty($keyword_name)) {
                report_error("Skipping: Empty keyword name for ID {$keyword_id}.");
                update_keyword_engine_search_status($keyword_id, $engine_id, $location_id, 'skipped_empty_keyword', 'Keyword name is empty.');
                continue;
            }
            if (empty($location_name)) {
                report_error("Skipping: Empty location name for ID {$location_id}.");
                update_keyword_engine_search_status($keyword_id, $engine_id, $location_id, 'skipped_empty_location', 'Location name is empty.');
                continue;
            }

            // Check if this (keyword, engine, location) triplet was recently searched
            $last_searched_dt = get_keyword_engine_search_status($keyword_id, $engine_id, $location_id);
            if ($last_searched_dt) {
                $now_dt = new DateTime();
                $interval = $now_dt->diff($last_searched_dt);
                $hours_since_last_search = ($interval->days * 24) + $interval->h;

                if ($hours_since_last_search < $SERP_COOLDOWN_HOURS) {
                    log_line("Skipping [{$engine_name}] / [{$keyword_name}] in [{$location_name}]: Last searched {$hours_since_last_search} hours ago, within {$SERP_COOLDOWN_HOURS}h cooldown.");
                    update_keyword_engine_search_status($keyword_id, $engine_id, $location_id, 'skipped_cooldown', "Skipped due to cooldown. Last search: " . $last_searched_dt->format('Y-m-d H:i:s'));
                    continue; // Skip this combination
                }
            }

            log_line("Searching [{$engine_name}] for [{$keyword_name}] in [{$location_name}]...");
            $search_status = 'processing';
            $search_notes = null;
            $query_for_serpapi = '';

            try {
                // Build query specific to engine, keyword, and location
                $query_for_serpapi = buildQuery($engine_name, $keyword_name, $location_name);

                if (empty($query_for_serpapi)) {
                    $error_msg = "Failed to build search query for engine '{$engine_name}', keyword '{$keyword_name}' and location '{$location_name}'.";
                    report_error($error_msg . " Skipping.");
                    $search_status = 'failed_query_build';
                    $search_notes = $error_msg;
                    update_keyword_engine_search_status($keyword_id, $engine_id, $location_id, $search_status, $search_notes);
                    continue;
                }

                $collectedForBucket = 0;
                $rank = 0;
                $page = 1;
                $pagesWithoutInsert = 0;
                $seenThisRun = [];

                while ($collectedForBucket < $RESULTS_PER_LOCATION && $page <= $MAX_PAGES) {
                    try {
                        $json = serpapi_search_page(
                            $engine_name,
                            $query_for_serpapi,
                            $RESULTS_PER_LOCATION * $OVERFETCH_FACTOR,
                            $page
                        );
                        $rawResults = parse_serp_results($json);
                    } catch (Throwable $e) {
                        report_error("{$engine_name} / {$keyword_name} in {$location_name} (page {$page}) failed: " . $e->getMessage());
                        $search_status = 'failed_api';
                        $search_notes = $e->getMessage();
                        break;
                    }

                    if (empty($rawResults)) {
                        log_line("No results for {$engine_name} / {$keyword_name} in {$location_name} on page {$page}.");
                        if ($search_status === 'processing') {
                            $search_status = 'no_results';
                            $search_notes = "No results found on page {$page}.";
                        }
                        break;
                    }

                    $insertedThisPage = 0;
                    foreach ($rawResults as $r) {
                        if ($collectedForBucket >= $RESULTS_PER_LOCATION) break;

                        $norm_url = normalize_url($r['url']);
                        if (!$norm_url) continue;
                        if (should_exclude($norm_url, $EXCLUDE_DOMAINS, $EXCLUDE_TLDS)) continue;

                        $domain_only_name = domain_only($norm_url);
                        if (!$domain_only_name) continue;

                        if (isset($seenThisRun[$domain_only_name])) {
                            $rank++;
                            print_url($engine_name, $location_name, $rank, $r['title'] ?? '', $norm_url, $domain_only_name, false);
                            continue;
                        }

                        if (isset($existingDomains[$domain_only_name])) {
                            $seenThisRun[$domain_only_name] = true;
                            $rank++;
                            print_url($engine_name, $location_name, $rank, $r['title'] ?? '', $norm_url, $domain_only_name, false);
                            continue;
                        }

                        try {
                            $ok = db_insert_domain($domain_only_name);
                            if ($ok) {
                                $existingDomains[$domain_only_name] = true;
                                $seenThisRun[$domain_only_name] = true;
                                $collectedForBucket++;
                                $totalInserted++;
                                $insertedThisPage++;
                            }
                        } catch (Throwable $e) {
                            report_error("Insert failed for domain [$domain_only_name]: " . $e->getMessage());
                        }

                        $rank++;
                        print_url($engine_name, $location_name, $rank, $r['title'] ?? '', $norm_url, $domain_only_name, $insertedThisPage > 0);
                    }

                    if ($insertedThisPage === 0) {
                        $pagesWithoutInsert++;
                    } else {
                        $pagesWithoutInsert = 0;
                    }

                    if ($pagesWithoutInsert >= 2) {
                        log_line("No new domains for two pages in a row; stopping for {$engine_name} / {$keyword_name} in {$location_name}.");
                        if ($search_status === 'processing') {
                            $search_status = 'completed_short';
                            $search_notes = "Stopped: no new domains for 2 consecutive pages.";
                        }
                        break;
                    }

                    $page++;
                    usleep(300000);
                }

                if ($search_status === 'processing') {
                    if ($collectedForBucket < $RESULTS_PER_LOCATION) {
                        $miss = $RESULTS_PER_LOCATION - $collectedForBucket;
                        $search_notes = ($search_notes ?? '') . " Short by {$miss} NEW domains.";
                        log_line("INFO: {$engine_name}/{$keyword_name} in {$location_name} short by {$miss} NEW domains after {$page} pages.");
                        $search_status = 'completed_short_target';
                    } else {
                        $search_status = 'completed_ok';
                        $search_notes = "Achieved {$RESULTS_PER_LOCATION} new domains.";
                    }
                }
            } catch (Throwable $e) {
                report_error("Unhandled error during search for [{$engine_name}] / [{$keyword_name}] in [{$location_name}]: " . $e->getMessage());
                $search_status = 'failed_unhandled_exception';
                $search_notes = $e->getMessage();
            }

            update_keyword_engine_search_status($keyword_id, $engine_id, $location_id, $search_status, $search_notes);

        } // End of foreach ($LOCATIONS)
    } // End of foreach ($KEYWORDS)
} // End of foreach ($ENGINES)

// Close the CSV file handler if it was opened
if ($fp) { fclose($fp); }

// ================== EMAIL SUMMARY & FINAL LOGGING ==================
// Retrieve email settings from the `settings` table. Defaults provided if not configured.
$EMAIL_TO = get_setting_value('email_to') ?? 'admin@example.com';
$EMAIL_FROM = get_setting_value('email_from') ?? 'no-reply@example.com';
$EMAIL_SUBJ_PREFIX = get_setting_value('email_subj_prefix') ?? 'SERP Report';

// New email notification toggles
$ENABLE_EMAIL_GETURLS_SUCCESS = (int)(get_setting_value('enable_email_geturls_success') ?? 1);
$ENABLE_EMAIL_GETURLS_ERROR = (int)(get_setting_value('enable_email_geturls_error') ?? 1);


// Debug: Log the email notification settings read from DB
stream_message("Email settings for getURLS.php:");
stream_message("  EMAIL_TO: " . ($EMAIL_TO ?? 'N/A')); // Use null coalesce for safety
stream_message("  ENABLE_EMAIL_GETURLS_SUCCESS: " . $ENABLE_EMAIL_GETURLS_SUCCESS);
stream_message("  ENABLE_EMAIL_GETURLS_ERROR: " . $ENABLE_EMAIL_GETURLS_ERROR);

// Construct email subject line based on run outcome
$subjectSuffix = ($errors ? 'Completed with Errors' : 'Success');
if (!empty($insertedDomains)) {
    $subjectSuffix .= ' - Inserted ' . count($insertedDomains);
}
$subject = $EMAIL_SUBJ_PREFIX . ' - ' . $subjectSuffix . " ($date)";

// Prepare email headers
$headers = [];
$headers[] = "From: {$EMAIL_FROM}";
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-Type: text/plain; charset=UTF-8";

// Build the email body content
$bodyLines = [];
$bodyLines[] = "Run date: $date";
$bodyLines[] = "Total NEW domains inserted: " . $totalInserted;
$bodyLines[] = "";

if (!empty($insertedDomains)) {
    $bodyLines[] = "Inserted Domains (" . count($insertedDomains) . "):";
    foreach ($insertedDomains as $dom) {
        $bodyLines[] = " - " . $dom;
    }
    $bodyLines[] = "";
}

if (!empty($errors)) {
    $bodyLines[] = "Errors:";
    foreach ($errors as $e) {
        $bodyLines[] = " - $e";
    }
    $bodyLines[] = "";
}

$bodyLines[] = "Results (INSERTED or SKIPPED):";
if (!empty($printedUrls)) {
    foreach ($printedUrls as $ln) {
        $bodyLines[] = $ln;
    }
} else {
    $bodyLines[] = "(No URLs processed.)";
}

$bodyLines[] = "";
if (is_file($outCsv)) {
    $bodyLines[] = "CSV saved: $outCsv";
} else {
    $bodyLines[] = "CSV not saved (check errors above).";
}

// Send summary email if a recipient is configured AND notifications are enabled
$shouldSendEmail = false;
if (!empty($EMAIL_TO)) {
    if (!empty($errors) && $ENABLE_EMAIL_GETURLS_ERROR === 1) {
        $shouldSendEmail = true; // Send email if there are errors and error notifications are enabled
    } elseif (empty($errors) && $ENABLE_EMAIL_GETURLS_SUCCESS === 1) {
        $shouldSendEmail = true; // Send email if no errors and success notifications are enabled
    }
} else {
    report_error("Email not sent: Recipient address (email_to) is not configured in settings.");
}

if ($shouldSendEmail) {
    @mail($EMAIL_TO, $subject, implode("\n", $bodyLines), implode("\r\n", $headers));
}


// Final summary message to console/log
log_line("Total NEW domains inserted: $totalInserted");
if (!empty($errors)) {
    log_line("Completed with errors (summary emailed if configured and enabled).");
} else {
    log_line("Completed successfully (summary emailed if configured and enabled).");
}