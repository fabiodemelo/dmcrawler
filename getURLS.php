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

// get_setting_value(), force_flush(), stream_message() are already loaded via db.php -> includes/functions.php

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

// log_activity() is already loaded via db.php -> includes/functions.php

// Phase 2: Load blacklist functions if available
$_blacklist_path = __DIR__ . '/includes/blacklist.php';
if (file_exists($_blacklist_path)) {
    require_once $_blacklist_path;
}

// ================== CONFIGURATION ==================

// SerpAPI API keys (round-robin) — loaded from .env (SERPAPI_KEYS, comma-separated)
$SERPAPI_KEYS = array_filter(array_map('trim', explode(',', $_ENV['SERPAPI_KEYS'] ?? '')));
if (empty($SERPAPI_KEYS)) {
    report_error("No SerpAPI keys configured. Set SERPAPI_KEYS in .env file.");
}

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
    // Blogging / website builder platforms
    '.blogspot.com', '.wordpress.com', '.wix.com', '.squarespace.com',
    '.weebly.com', '.jimdo.com', '.site123.com', '.webflow.io',
    '.godaddysites.com', '.carrd.co', '.strikingly.com',
    // Social media / directories
    '.yelp.com', '.facebook.com', '.linkedin.com', '.instagram.com',
    '.twitter.com', '.x.com', '.pinterest.com', '.tiktok.com',
    '.youtube.com', '.reddit.com', '.quora.com', '.medium.com',
    // Google properties
    '.google.com', '.maps.google.com', '.googleapis.com', '.goo.gl',
    // Business directories / aggregators
    '.yellowpages.com', '.bbb.org', '.angi.com', '.homeadvisor.com',
    '.thumbtack.com', '.manta.com', '.houzz.com', '.chamberofcommerce.com',
    '.crunchbase.com', '.zoominfo.com', '.dnb.com', '.glassdoor.com',
    '.indeed.com', '.buildzoom.com', '.porch.com', '.bark.com',
    // Classifieds / marketplaces
    '.amazon.com', '.ebay.com', '.etsy.com', '.alibaba.com',
    '.craigslist.org', '.nextdoor.com',
    // News / media
    '.wikipedia.org', '.wikihow.com', '.nytimes.com', '.cnn.com',
    // URL shorteners / redirect services
    '.bit.ly', '.tinyurl.com', '.t.co',
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

// ================== SEARCH QUERY BUILDING (3-Phase Strategy) ==================

/**
 * Detects the language code for a location based on country mapping.
 * Returns 'pt' for Brazil, 'es' for Spanish countries, etc. Defaults to 'en'.
 */
function detect_location_language(string $location): string {
    $langMap = [
        'brasil' => 'pt', 'brazil' => 'pt', 'portugal' => 'pt',
        'mexico' => 'es', 'méxico' => 'es', 'argentina' => 'es', 'chile' => 'es',
        'colombia' => 'es', 'peru' => 'es', 'perú' => 'es', 'spain' => 'es', 'españa' => 'es',
        'uruguay' => 'es', 'paraguay' => 'es', 'ecuador' => 'es', 'venezuela' => 'es',
        'costa rica' => 'es', 'panama' => 'es', 'bolivia' => 'es', 'guatemala' => 'es',
        'honduras' => 'es', 'el salvador' => 'es', 'nicaragua' => 'es', 'cuba' => 'es',
        'dominican republic' => 'es', 'puerto rico' => 'es',
        'france' => 'fr', 'morocco' => 'fr', 'belgium' => 'fr',
        'germany' => 'de', 'austria' => 'de', 'switzerland' => 'de',
        'italy' => 'it', 'japan' => 'ja', 'south korea' => 'ko',
        'russia' => 'ru', 'turkey' => 'tr', 'poland' => 'pl',
        'netherlands' => 'nl', 'sweden' => 'sv', 'norway' => 'no',
        'denmark' => 'da', 'finland' => 'fi', 'czech republic' => 'cs',
        'romania' => 'ro', 'hungary' => 'hu', 'greece' => 'el',
    ];
    return $langMap[mb_strtolower(trim($location))] ?? 'en';
}

/**
 * Phase 1: Broad Discovery — natural language queries, no site: restrictions.
 * Uses language-appropriate connectors based on location country.
 */
function buildQuery_phase1(string $engine, string $keyword, string $location): string {
    if (empty($keyword) || empty($location)) return '';

    if ($engine === 'google_maps') {
        return "{$keyword} {$location}";
    }

    // When using SerpAPI gl/google_domain (country-targeted), the location in the
    // query is redundant and can dilute results. Use keyword + location simply.
    $lang = detect_location_language($location);
    switch ($lang) {
        case 'pt':
            return "{$keyword} {$location}";
        case 'es':
            return "{$keyword} {$location}";
        case 'fr':
            return "{$keyword} {$location}";
        case 'de':
            return "{$keyword} {$location}";
        default:
            return "{$keyword} {$location}";
    }
}

/**
 * Phase 2: Intent-Based Discovery — queries that target contact/about pages directly.
 * Uses language-appropriate intent phrases based on location country.
 */
function buildQueries_phase2(string $engine, string $keyword, string $location): array {
    if (empty($keyword) || empty($location)) return [];

    if ($engine === 'google_maps') {
        return ["{$keyword} {$location}"];
    }

    $lang = detect_location_language($location);

    switch ($lang) {
        case 'pt':
            return [
                "{$keyword} {$location} contato email",
                "{$keyword} {$location} \"sobre nós\"",
                "melhores empresas de {$keyword} {$location}",
                "{$keyword} {$location} fornecedores",
                "{$keyword} {$location} empresas",
            ];
        case 'es':
            return [
                "{$keyword} {$location} contacto email",
                "{$keyword} {$location} \"sobre nosotros\"",
                "mejores empresas de {$keyword} {$location}",
                "{$keyword} {$location} proveedores",
                "{$keyword} {$location} empresas",
            ];
        case 'fr':
            return [
                "{$keyword} {$location} contact email",
                "{$keyword} {$location} \"à propos\"",
                "meilleures entreprises {$keyword} {$location}",
                "{$keyword} {$location} fournisseurs",
                "{$keyword} {$location} entreprises",
            ];
        case 'de':
            return [
                "{$keyword} {$location} kontakt email",
                "{$keyword} {$location} \"über uns\"",
                "beste {$keyword} unternehmen {$location}",
                "{$keyword} {$location} lieferanten",
                "{$keyword} {$location} firmen",
            ];
        default:
            return [
                "{$keyword} {$location} contact us email",
                "{$keyword} {$location} \"about us\"",
                "best {$keyword} companies in {$location}",
                "top {$keyword} {$location} reviews",
                "{$keyword} {$location} services",
            ];
    }
}

/**
 * Phase 3: Competitor Mining — finds similar businesses based on top-performing domains.
 * Uses "related:" operator (Google only) or similar domain queries.
 */
function buildQueries_phase3(string $engine, array $seedDomains): array {
    if (empty($seedDomains)) return [];

    $queries = [];
    foreach ($seedDomains as $domain) {
        if ($engine === 'google') {
            $queries[] = "related:{$domain}";
        } else {
            // For non-Google engines, search for the domain to find competitors in same results
            $queries[] = "\"{$domain}\" competitors alternatives";
        }
    }
    return $queries;
}

/**
 * Legacy wrapper — calls Phase 1 by default for backward compatibility.
 */
function buildQuery(string $engine, string $keyword, string $location): string {
    return buildQuery_phase1($engine, $keyword, $location);
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
function serpapi_search_page(string $engine, string $query, int $num, int $page = 1, ?string $location = null): array {
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

    // Pass location to SerpAPI so searches happen in the correct region/country
    if ($location !== null && $location !== '') {
        // SerpAPI requires English location names from their database
        // Map common native-language names to SerpAPI-compatible English names
        $serpLocationMap = [
            'brasil' => 'Brazil',
            'méxico' => 'Mexico',
            'españa' => 'Spain',
            'perú' => 'Peru',
        ];
        $locLower = mb_strtolower(trim($location));
        $params['location'] = $serpLocationMap[$locLower] ?? $location;

        // Map country names to Google country domains and gl codes
        // When a location matches a country, use that country's Google domain
        $countryMap = [
            'brasil' => ['google_domain' => 'google.com.br', 'gl' => 'br', 'hl' => 'pt'],
            'brazil' => ['google_domain' => 'google.com.br', 'gl' => 'br', 'hl' => 'pt'],
            'mexico' => ['google_domain' => 'google.com.mx', 'gl' => 'mx', 'hl' => 'es'],
            'méxico' => ['google_domain' => 'google.com.mx', 'gl' => 'mx', 'hl' => 'es'],
            'argentina' => ['google_domain' => 'google.com.ar', 'gl' => 'ar', 'hl' => 'es'],
            'chile' => ['google_domain' => 'google.cl', 'gl' => 'cl', 'hl' => 'es'],
            'colombia' => ['google_domain' => 'google.com.co', 'gl' => 'co', 'hl' => 'es'],
            'peru' => ['google_domain' => 'google.com.pe', 'gl' => 'pe', 'hl' => 'es'],
            'perú' => ['google_domain' => 'google.com.pe', 'gl' => 'pe', 'hl' => 'es'],
            'spain' => ['google_domain' => 'google.es', 'gl' => 'es', 'hl' => 'es'],
            'españa' => ['google_domain' => 'google.es', 'gl' => 'es', 'hl' => 'es'],
            'portugal' => ['google_domain' => 'google.pt', 'gl' => 'pt', 'hl' => 'pt'],
            'france' => ['google_domain' => 'google.fr', 'gl' => 'fr', 'hl' => 'fr'],
            'germany' => ['google_domain' => 'google.de', 'gl' => 'de', 'hl' => 'de'],
            'italy' => ['google_domain' => 'google.it', 'gl' => 'it', 'hl' => 'it'],
            'united kingdom' => ['google_domain' => 'google.co.uk', 'gl' => 'uk', 'hl' => 'en'],
            'uk' => ['google_domain' => 'google.co.uk', 'gl' => 'uk', 'hl' => 'en'],
            'canada' => ['google_domain' => 'google.ca', 'gl' => 'ca', 'hl' => 'en'],
            'australia' => ['google_domain' => 'google.com.au', 'gl' => 'au', 'hl' => 'en'],
            'japan' => ['google_domain' => 'google.co.jp', 'gl' => 'jp', 'hl' => 'ja'],
            'india' => ['google_domain' => 'google.co.in', 'gl' => 'in', 'hl' => 'en'],
            'south africa' => ['google_domain' => 'google.co.za', 'gl' => 'za', 'hl' => 'en'],
            'netherlands' => ['google_domain' => 'google.nl', 'gl' => 'nl', 'hl' => 'nl'],
            'belgium' => ['google_domain' => 'google.be', 'gl' => 'be', 'hl' => 'nl'],
            'switzerland' => ['google_domain' => 'google.ch', 'gl' => 'ch', 'hl' => 'de'],
            'austria' => ['google_domain' => 'google.at', 'gl' => 'at', 'hl' => 'de'],
            'sweden' => ['google_domain' => 'google.se', 'gl' => 'se', 'hl' => 'sv'],
            'norway' => ['google_domain' => 'google.no', 'gl' => 'no', 'hl' => 'no'],
            'denmark' => ['google_domain' => 'google.dk', 'gl' => 'dk', 'hl' => 'da'],
            'finland' => ['google_domain' => 'google.fi', 'gl' => 'fi', 'hl' => 'fi'],
            'poland' => ['google_domain' => 'google.pl', 'gl' => 'pl', 'hl' => 'pl'],
            'russia' => ['google_domain' => 'google.ru', 'gl' => 'ru', 'hl' => 'ru'],
            'turkey' => ['google_domain' => 'google.com.tr', 'gl' => 'tr', 'hl' => 'tr'],
            'south korea' => ['google_domain' => 'google.co.kr', 'gl' => 'kr', 'hl' => 'ko'],
            'china' => ['google_domain' => 'google.com.hk', 'gl' => 'hk', 'hl' => 'zh-CN'],
            'taiwan' => ['google_domain' => 'google.com.tw', 'gl' => 'tw', 'hl' => 'zh-TW'],
            'singapore' => ['google_domain' => 'google.com.sg', 'gl' => 'sg', 'hl' => 'en'],
            'new zealand' => ['google_domain' => 'google.co.nz', 'gl' => 'nz', 'hl' => 'en'],
            'ireland' => ['google_domain' => 'google.ie', 'gl' => 'ie', 'hl' => 'en'],
            'israel' => ['google_domain' => 'google.co.il', 'gl' => 'il', 'hl' => 'he'],
            'egypt' => ['google_domain' => 'google.com.eg', 'gl' => 'eg', 'hl' => 'ar'],
            'nigeria' => ['google_domain' => 'google.com.ng', 'gl' => 'ng', 'hl' => 'en'],
            'kenya' => ['google_domain' => 'google.co.ke', 'gl' => 'ke', 'hl' => 'en'],
            'indonesia' => ['google_domain' => 'google.co.id', 'gl' => 'id', 'hl' => 'id'],
            'malaysia' => ['google_domain' => 'google.com.my', 'gl' => 'my', 'hl' => 'ms'],
            'philippines' => ['google_domain' => 'google.com.ph', 'gl' => 'ph', 'hl' => 'en'],
            'thailand' => ['google_domain' => 'google.co.th', 'gl' => 'th', 'hl' => 'th'],
            'vietnam' => ['google_domain' => 'google.com.vn', 'gl' => 'vn', 'hl' => 'vi'],
            'czech republic' => ['google_domain' => 'google.cz', 'gl' => 'cz', 'hl' => 'cs'],
            'romania' => ['google_domain' => 'google.ro', 'gl' => 'ro', 'hl' => 'ro'],
            'hungary' => ['google_domain' => 'google.hu', 'gl' => 'hu', 'hl' => 'hu'],
            'greece' => ['google_domain' => 'google.gr', 'gl' => 'gr', 'hl' => 'el'],
            'ukraine' => ['google_domain' => 'google.com.ua', 'gl' => 'ua', 'hl' => 'uk'],
            'uruguay' => ['google_domain' => 'google.com.uy', 'gl' => 'uy', 'hl' => 'es'],
            'paraguay' => ['google_domain' => 'google.com.py', 'gl' => 'py', 'hl' => 'es'],
            'ecuador' => ['google_domain' => 'google.com.ec', 'gl' => 'ec', 'hl' => 'es'],
            'venezuela' => ['google_domain' => 'google.co.ve', 'gl' => 've', 'hl' => 'es'],
            'costa rica' => ['google_domain' => 'google.co.cr', 'gl' => 'cr', 'hl' => 'es'],
            'panama' => ['google_domain' => 'google.com.pa', 'gl' => 'pa', 'hl' => 'es'],
            'dominican republic' => ['google_domain' => 'google.com.do', 'gl' => 'do', 'hl' => 'es'],
            'guatemala' => ['google_domain' => 'google.com.gt', 'gl' => 'gt', 'hl' => 'es'],
            'bolivia' => ['google_domain' => 'google.com.bo', 'gl' => 'bo', 'hl' => 'es'],
            'honduras' => ['google_domain' => 'google.hn', 'gl' => 'hn', 'hl' => 'es'],
            'el salvador' => ['google_domain' => 'google.com.sv', 'gl' => 'sv', 'hl' => 'es'],
            'nicaragua' => ['google_domain' => 'google.com.ni', 'gl' => 'ni', 'hl' => 'es'],
            'cuba' => ['google_domain' => 'google.com.cu', 'gl' => 'cu', 'hl' => 'es'],
            'puerto rico' => ['google_domain' => 'google.com.pr', 'gl' => 'pr', 'hl' => 'es'],
            'morocco' => ['google_domain' => 'google.co.ma', 'gl' => 'ma', 'hl' => 'fr'],
            'saudi arabia' => ['google_domain' => 'google.com.sa', 'gl' => 'sa', 'hl' => 'ar'],
            'uae' => ['google_domain' => 'google.ae', 'gl' => 'ae', 'hl' => 'ar'],
            'united arab emirates' => ['google_domain' => 'google.ae', 'gl' => 'ae', 'hl' => 'ar'],
            'pakistan' => ['google_domain' => 'google.com.pk', 'gl' => 'pk', 'hl' => 'en'],
            'bangladesh' => ['google_domain' => 'google.com.bd', 'gl' => 'bd', 'hl' => 'bn'],
        ];

        // Check if location matches a known country (case-insensitive)
        $locationLower = mb_strtolower(trim($location));
        if (isset($countryMap[$locationLower])) {
            $countryInfo = $countryMap[$locationLower];
            $params['google_domain'] = $countryInfo['google_domain'];
            $params['gl'] = $countryInfo['gl'];
            $params['hl'] = $countryInfo['hl'];
        }
    }

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
 * Phase 3: Get seed domains — top-performing crawled domains that found emails.
 * These are used to find similar/competitor businesses via "related:" queries.
 */
function get_seed_domains(int $limit = 15): array {
    $domains = [];
    try {
        $stmt = db()->query("
            SELECT domain FROM domains
            WHERE crawled = 1
              AND emails_found > 0
              AND (blacklisted = 0 OR blacklisted IS NULL)
            ORDER BY emails_found DESC, quality_score DESC
            LIMIT {$limit}
        ");
        while ($row = $stmt->fetch()) {
            $domains[] = $row['domain'];
        }
    } catch (Throwable $e) {
        // quality_score column might not exist if Phase 2 migration hasn't run
        try {
            $stmt = db()->query("
                SELECT domain FROM domains
                WHERE crawled = 1 AND emails_found > 0
                ORDER BY emails_found DESC
                LIMIT {$limit}
            ");
            while ($row = $stmt->fetch()) {
                $domains[] = $row['domain'];
            }
        } catch (Throwable $e2) {
            report_error("Failed to load seed domains: " . $e2->getMessage());
        }
    }
    return $domains;
}

/**
 * Inserts a new unique domain into the `domains` table.
 * @param string $domain The domain name to insert.
 * @return bool True on successful insertion.
 * @throws PDOException On database errors.
 */
function db_insert_domain(string $domain, ?int $campaignId = null, ?string $keyword = null, ?string $location = null): bool {
    $sql = "INSERT INTO `domains` (`id`, `domain`, `crawled`, `date_added`, `date_crawled`, `emails_found`, `urls_crawled`, `campaign_id`, `source_keyword`, `source_location`)
            VALUES (NULL, :d, 0, CURRENT_TIMESTAMP, NULL, 0, 0, :cid, :kw, :loc)";
    $stmt = db()->prepare($sql);
    $stmt->execute([':d' => $domain, ':cid' => $campaignId, ':kw' => $keyword, ':loc' => $location]);
    return true;
}

/**
 * Fetches active keywords (status = 1) from the `keywords` table.
 * If a campaign ID is provided and has keyword groups assigned, only returns
 * keywords belonging to those groups. Falls back to all active keywords otherwise.
 * @param int|null $campaignId The active campaign ID (or null for all).
 * @return array An array of associative arrays, each containing 'id' and 'keyword'.
 */
function fetch_active_keywords(?int $campaignId = null): array {
    $keywords = [];
    try {
        // If campaign has keyword groups assigned, filter by them
        if ($campaignId !== null) {
            $checkStmt = db()->prepare("SELECT COUNT(*) FROM `campaign_keyword_groups` WHERE `campaign_id` = ?");
            $checkStmt->execute([$campaignId]);
            $hasGroups = (int)$checkStmt->fetchColumn() > 0;

            if ($hasGroups) {
                $stmt = db()->prepare("
                    SELECT DISTINCT k.`id`, k.`keyword`
                    FROM `keywords` k
                    INNER JOIN `keyword_group_items` kgi ON k.`id` = kgi.`keyword_id`
                    INNER JOIN `campaign_keyword_groups` ckg ON kgi.`group_id` = ckg.`group_id`
                    WHERE k.`status` = 1 AND ckg.`campaign_id` = ?
                ");
                $stmt->execute([$campaignId]);
                while ($row = $stmt->fetch()) {
                    $keywords[] = $row;
                }
                log_line("Filtered keywords by campaign #{$campaignId} groups: " . count($keywords) . " keywords found.");
                return $keywords;
            }
        }

        // Fallback: all active keywords
        $stmt = db()->query("SELECT `id`, `keyword` FROM `keywords` WHERE `status` = 1");
        while ($row = $stmt->fetch()) {
            $keywords[] = $row;
        }
        return $keywords;
    } catch (Throwable $e) {
        report_error("Failed to fetch active keywords from DB: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches active locations (status = 1) from the `locations` table.
 * If a campaign ID is provided and has location groups assigned, only returns
 * locations belonging to those groups. Falls back to all active locations otherwise.
 * @param int|null $campaignId The active campaign ID (or null for all).
 * @return array An array of associative arrays, each containing 'id' and 'name'.
 */
function fetch_active_locations(?int $campaignId = null): array {
    $locations = [];
    try {
        // If campaign has location groups assigned, filter by them
        if ($campaignId !== null) {
            $checkStmt = db()->prepare("SELECT COUNT(*) FROM `campaign_location_groups` WHERE `campaign_id` = ?");
            $checkStmt->execute([$campaignId]);
            $hasGroups = (int)$checkStmt->fetchColumn() > 0;

            if ($hasGroups) {
                $stmt = db()->prepare("
                    SELECT DISTINCT l.`id`, l.`name`
                    FROM `locations` l
                    INNER JOIN `location_group_items` lgi ON l.`id` = lgi.`location_id`
                    INNER JOIN `campaign_location_groups` clg ON lgi.`group_id` = clg.`group_id`
                    WHERE l.`status` = 1 AND clg.`campaign_id` = ?
                ");
                $stmt->execute([$campaignId]);
                while ($row = $stmt->fetch()) {
                    $locations[] = $row;
                }
                log_line("Filtered locations by campaign #{$campaignId} groups: " . count($locations) . " locations found.");
                return $locations;
            }
        }

        // Fallback: all active locations
        $stmt = db()->query("SELECT `id`, `name` FROM `locations` WHERE `status` = 1");
        while ($row = $stmt->fetch()) {
            $locations[] = $row;
        }
        return $locations;
    } catch (Throwable $e) {
        report_error("Failed to fetch active locations from DB: " . $e->getMessage());
        return [];
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

// Fetch active campaign (must be before keywords/locations so we can filter by campaign groups)
$ACTIVE_CAMPAIGN = null;
try {
    $campStmt = db()->query("SELECT id, name FROM campaigns WHERE status = 1 LIMIT 1");
    $ACTIVE_CAMPAIGN = $campStmt ? $campStmt->fetch() : null;
} catch (Throwable $e) {
    // campaigns table may not exist yet — proceed without
}
if (!$ACTIVE_CAMPAIGN) {
    log_line("WARNING: No active campaign found. Domains will be inserted without campaign tracking.");
}

$activeCampaignId = $ACTIVE_CAMPAIGN ? (int)$ACTIVE_CAMPAIGN['id'] : null;

// Fetch active keywords — filtered by campaign groups if assigned
$KEYWORDS = fetch_active_keywords($activeCampaignId);
if (empty($KEYWORDS)) {
    report_error("No active keywords found for the current campaign. Check keyword groups assignment.");
    exit(1);
}

// Fetch active locations — filtered by campaign groups if assigned
$LOCATIONS = fetch_active_locations($activeCampaignId);
if (empty($LOCATIONS)) {
    report_error("No active locations found for the current campaign. Check location groups assignment.");
    exit(1);
}

// Fetch active engines from the database
$ENGINES = fetch_active_engines();
if (empty($ENGINES)) {
    report_error("No active engines found in the database. Please add engines with status = 1.");
    exit(1);
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

// ================== CORE SEARCH FUNCTION ==================
/**
 * Executes a single search query against SerpAPI and inserts new domains.
 * Returns the number of new domains inserted.
 */
function execute_search(string $engine_name, string $query, string $label, int $maxResults, array &$existingDomains, array &$seenThisRun, ?string $keyword = null, ?string $location = null): int {
    global $RESULTS_PER_LOCATION, $MAX_PAGES, $OVERFETCH_FACTOR, $EXCLUDE_DOMAINS, $EXCLUDE_TLDS;
    global $totalInserted, $errors, $fp, $ACTIVE_CAMPAIGN;

    if (empty($query)) return 0;

    $collected = 0;
    $rank = 0;
    $page = 1;
    $pagesWithoutInsert = 0;
    $targetResults = min($maxResults, $RESULTS_PER_LOCATION);

    while ($collected < $targetResults && $page <= $MAX_PAGES) {
        try {
            $json = serpapi_search_page($engine_name, $query, $targetResults * $OVERFETCH_FACTOR, $page, $location);
            $rawResults = parse_serp_results($json);
        } catch (Throwable $e) {
            report_error("{$label} (page {$page}) failed: " . $e->getMessage());
            break;
        }

        if (empty($rawResults)) break;

        $insertedThisPage = 0;
        foreach ($rawResults as $r) {
            if ($collected >= $targetResults) break;

            $norm_url = normalize_url($r['url']);
            if (!$norm_url) continue;
            if (should_exclude($norm_url, $EXCLUDE_DOMAINS, $EXCLUDE_TLDS)) continue;

            $domain_only_name = domain_only($norm_url);
            if (!$domain_only_name) continue;

            if (isset($seenThisRun[$domain_only_name]) || isset($existingDomains[$domain_only_name])) {
                $seenThisRun[$domain_only_name] = true;
                $rank++;
                continue;
            }

            if (function_exists('is_domain_blacklisted') && is_domain_blacklisted($domain_only_name)) {
                $seenThisRun[$domain_only_name] = true;
                continue;
            }

            try {
                $campId = $ACTIVE_CAMPAIGN ? (int)$ACTIVE_CAMPAIGN['id'] : null;
                if (db_insert_domain($domain_only_name, $campId, $keyword, $location)) {
                    $existingDomains[$domain_only_name] = true;
                    $seenThisRun[$domain_only_name] = true;
                    $collected++;
                    $totalInserted++;
                    $insertedThisPage++;
                    $rank++;
                    print_url($engine_name, $label, $rank, $r['title'] ?? '', $norm_url, $domain_only_name, true);
                }
            } catch (Throwable $e) {
                report_error("Insert failed for [{$domain_only_name}]: " . $e->getMessage());
            }
        }

        if ($insertedThisPage === 0) {
            $pagesWithoutInsert++;
        } else {
            $pagesWithoutInsert = 0;
        }

        if ($pagesWithoutInsert >= 2) {
            log_line("  No new domains for 2 pages — moving on.");
            break;
        }

        $page++;
        usleep(300000);
    }

    return $collected;
}

// ================== 3-PHASE MAIN SEARCH LOOP ==================

$seenThisRun = []; // Global seen tracker across all phases

// ═══════════════════════════════════════════════════════════════
// PHASE 1: Broad Discovery
// Natural language queries — "keyword near location"
// ═══════════════════════════════════════════════════════════════
log_line("═══ PHASE 1: Broad Discovery ═══");

foreach ($ENGINES as $engine_data) {
    $engine_id = (int)$engine_data['ID'];
    $engine_name = trim($engine_data['name']);
    if (empty($engine_name)) continue;

    foreach ($KEYWORDS as $keyword_data) {
        $keyword_id = (int)$keyword_data['id'];
        $keyword_name = trim($keyword_data['keyword']);
        if (empty($keyword_name)) continue;

        foreach ($LOCATIONS as $location_data) {
            $location_id = (int)$location_data['id'];
            $location_name = trim($location_data['name']);
            if (empty($location_name)) continue;

            // Check cooldown
            $last_searched_dt = get_keyword_engine_search_status($keyword_id, $engine_id, $location_id);
            if ($last_searched_dt) {
                $hours_since = ((new DateTime())->diff($last_searched_dt)->days * 24) + (new DateTime())->diff($last_searched_dt)->h;
                if ($hours_since < $SERP_COOLDOWN_HOURS) {
                    log_line("  SKIP (cooldown): [{$engine_name}] {$keyword_name} in {$location_name}");
                    continue;
                }
            }

            $query = buildQuery_phase1($engine_name, $keyword_name, $location_name);
            $label = "{$location_name} / {$keyword_name}";

            log_line("P1: [{$engine_name}] {$keyword_name} near {$location_name}");
            @file_put_contents(__DIR__ . '/search_activity.json', json_encode([
                'phase' => 1,
                'engine' => $engine_name,
                'keyword' => $keyword_name,
                'location' => $location_name,
                'query' => $query,
                'started_at' => date('Y-m-d H:i:s'),
                'inserted_so_far' => $totalInserted,
            ]));

            $found = execute_search($engine_name, $query, $label, $RESULTS_PER_LOCATION, $existingDomains, $seenThisRun, $keyword_name, $location_name);
            $status = $found > 0 ? 'completed_ok' : 'no_results';
            update_keyword_engine_search_status($keyword_id, $engine_id, $location_id, $status, "Phase 1: found {$found} new domains");
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// PHASE 2: Intent-Based Discovery
// Queries targeting contact pages, about pages, review lists
// ═══════════════════════════════════════════════════════════════
log_line("═══ PHASE 2: Intent-Based Discovery ═══");

foreach ($ENGINES as $engine_data) {
    $engine_name = trim($engine_data['name']);
    if (empty($engine_name)) continue;
    // Skip google_maps for Phase 2 — it was already handled in Phase 1
    if ($engine_name === 'google_maps') continue;

    foreach ($KEYWORDS as $keyword_data) {
        $keyword_name = trim($keyword_data['keyword']);
        if (empty($keyword_name)) continue;

        foreach ($LOCATIONS as $location_data) {
            $location_name = trim($location_data['name']);
            if (empty($location_name)) continue;

            $queries = buildQueries_phase2($engine_name, $keyword_name, $location_name);
            // Only run 2 intent queries per combo to manage API usage
            $queries = array_slice($queries, 0, 2);

            foreach ($queries as $qi => $query) {
                $label = "P2-{$qi}: {$location_name} / {$keyword_name}";
                log_line("P2: [{$engine_name}] {$query}");
                @file_put_contents(__DIR__ . '/search_activity.json', json_encode([
                    'phase' => 2,
                    'engine' => $engine_name,
                    'keyword' => $keyword_name,
                    'location' => $location_name,
                    'query' => $query,
                    'started_at' => date('Y-m-d H:i:s'),
                    'inserted_so_far' => $totalInserted,
                ]));

                // Lower target for intent queries — they're supplementary
                execute_search($engine_name, $query, $label, 20, $existingDomains, $seenThisRun, $keyword_name, $location_name);
                usleep(500000); // Extra delay between intent queries
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// PHASE 3: Competitor Mining
// "related:domain.com" queries based on top-performing domains
// ═══════════════════════════════════════════════════════════════
log_line("═══ PHASE 3: Competitor Mining ═══");

$seedDomains = get_seed_domains(15);
if (empty($seedDomains)) {
    log_line("  No seed domains available for competitor mining (need crawled domains with emails).");
} else {
    log_line("  Using " . count($seedDomains) . " seed domains: " . implode(', ', array_slice($seedDomains, 0, 5)) . '...');

    // Only run Phase 3 on google (related: is a Google operator)
    foreach (['google'] as $engine_name) {
        $queries = buildQueries_phase3($engine_name, $seedDomains);

        foreach ($queries as $qi => $query) {
            $seedDomain = $seedDomains[$qi] ?? 'unknown';
            $label = "P3: related to {$seedDomain}";
            log_line("P3: [{$engine_name}] {$query}");
            @file_put_contents(__DIR__ . '/search_activity.json', json_encode([
                'phase' => 3,
                'engine' => $engine_name,
                'keyword' => "related:{$seedDomain}",
                'location' => 'competitor mining',
                'query' => $query,
                'started_at' => date('Y-m-d H:i:s'),
                'inserted_so_far' => $totalInserted,
            ]));

            execute_search($engine_name, $query, $label, 10, $existingDomains, $seenThisRun, null, null);
            usleep(500000);
        }
    }
}

log_line("═══ All 3 phases complete ═══");

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

// Per-run success emails disabled — use daily_crawler_report.php for consolidated daily summaries.
// Only send immediate email for error runs that need attention.
if (!empty($errors) && !empty($EMAIL_TO) && $ENABLE_EMAIL_GETURLS_ERROR === 1) {
    @mail($EMAIL_TO, $subject, implode("\n", $bodyLines), implode("\r\n", $headers));
}


// Clear search activity file
@unlink(__DIR__ . '/search_activity.json');

// Final summary message to console/log
log_line("Total NEW domains inserted: $totalInserted");
if (!empty($errors)) {
    log_line("Completed with errors (summary emailed if configured and enabled).");
} else {
    log_line("Completed successfully (summary emailed if configured and enabled).");
}