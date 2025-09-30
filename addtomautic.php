<?php
/**
 * addtomautic.php
 * ----------------
 * Reads contacts from MySQL table `emails` and inserts them into Mautic.
 * On success, updates `emails.ma = 1`.
 *
 * Modes:
 *   1) API mode (recommended): create/upsert contact, then add to segment via REST.
 *   2) Form mode: submit to a public Mautic Form that adds the contact to a Segment.
 *
 * Run manually:
 *   php addtomautic.php
 *
 * Cron example (every hour):
 *   15 * * * * /usr/bin/php /path/to/addtomautic.php >/dev/null 2>&1
 */

declare(strict_types=1);
include 'db.php';

// --- START LOCK FILE MANAGEMENT ---
$lock_file = __DIR__ . '/addtomautic.running';

// Create lock file at the very beginning of the script execution, storing start time
file_put_contents($lock_file, date('Y-m-d H:i:s'));

// Register a shutdown function to ensure the lock file is removed even if the script crashes
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
});
// --- END LOCK FILE MANAGEMENT ---

// Helper to read a single setting field safely (creates it only if missing)
if (!function_exists('get_setting_value')) {
    function get_setting_value($field) {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) return null;
        global $conn;
        $res = $conn->query("SELECT `{$field}` AS v FROM settings WHERE id = 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return $row['v'];
        }
        return null;
    }
}

// Map variables to settings columns as requested
$EMAIL_TO            = (string) (get_setting_value('email_from')        ?? ''); // $EMAIL_TO -> email_from
$EMAIL_FROM          = (string) (get_setting_value('email_to')          ?? ''); // $EMAIL_FROM -> email_to
$MAUTIC_BASE_URL     = (string) (get_setting_value('mautic_api_url')    ?? '');
$MAUTIC_USERNAME     = (string) (get_setting_value('mautic_api_username') ?? '');
$MAUTIC_PASSWORD     = (string) (get_setting_value('mautic_password')   ?? '');
$MAUTIC_SEGMENT_ID   = (int)    (get_setting_value('mautic_seg_id')    ?? 0);  // both map to mautic_form_id
$MAUTIC_FORM_ID      = (int)    (get_setting_value('mautic_form_id')    ?? 0);
$MAUTIC_FORM_NAME    = (string) (get_setting_value('mautic_form_name')  ?? '');
$MAUTIC_FORM_POST_URL= (string) (get_setting_value('mautic_form_post_url') ?? '');
$BATCH_LIMIT         = (int) (get_setting_value('mautic_batch_limit') ?? '');
$limit_max           = (int) (get_setting_value('mautic_limit_max') ?? '');
$SLEEP_BETWEEN       = (int) (get_setting_value('mautic_between') ?? '');

require_once __DIR__ . '/db.php';
// ================== DB (PDO wrapper using credentials from db.php) ==================
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    global $host, $db, $user, $pass;
    $dsn = "mysql:host={$host};dbname={$db};charset=latin1";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// ================== CONFIG ==================
// --- Choose how to send to Mautic: 'api' or 'form'
const MAUTIC_MODE = 'api'; // 'api' | 'form'
$EMAIL_SUBJ = 'Mautic Import Summary';
$MAUTIC_FORM_FIELD_MAP = [
    // 'firstname' => 'name',   // uncomment if your form has a "firstname" field
    'email' => 'email',
];
// ================== INTERNAL STATE ==================
$errors = [];
$info = [];
$successCount = 0;
$skipCount = 0;

function say(string $line): void
{
    global $info;
    $info[] = $line;
    if (php_sapi_name() === 'cli') {
        echo $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line) . "<br>\n";
    }
    @ob_flush();
    @flush();
}

function err(string $line): void
{
    global $errors;
    $errors[] = $line;
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "[ERROR] $line" . PHP_EOL);
    } else {
        echo '<div style="color:#b00020;font-weight:bold;">' . htmlspecialchars("[ERROR] $line") . "</div>\n";
    }
    @ob_flush();
    @flush();
}

// ================== DB QUERIES (using shared db()) ==================
function fetch_pending_emails(int $limit): array
{
    $sql = "SELECT id, domain_id, name, email, ma
            FROM emails
            WHERE (ma IS NULL OR ma = 0)
              AND email IS NOT NULL AND email <> ''
            ORDER BY id ASC
            LIMIT :lim";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_email_done(int $id): void
{
    $sql = "UPDATE emails SET ma = 1 WHERE id = :id";
    $stmt = db()->prepare($sql);
    $stmt->execute([':id' => $id]);
}

// ================== HTTP ==================
function http_request(string $method, string $url, array $opts = []): array
{
    $ch = curl_init();
    $headers = $opts['headers'] ?? [];
    $timeout = $opts['timeout'] ?? 25;
    $authUser = $opts['auth_user'] ?? null;
    $authPass = $opts['auth_pass'] ?? null;
    $postFields = $opts['post_fields'] ?? null;
    $jsonBody = $opts['json'] ?? null;

    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'MauticImporter/1.0 (+PHP cURL)',
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($authUser !== null && $authPass !== null) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $authUser . ':' . $authPass);
    }

    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } elseif ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("HTTP request failed: $err");
    }
    return ['code' => $code, 'body' => $body];
}

// ================== MAUTIC (API MODE) ==================
function mautic_api_find_contact_by_email(string $base, string $user, string $pass, string $email): ?int
{
    // GET /api/contacts?search=email:<email>
    $url = rtrim($base, '/') . '/api/contacts?search=' . rawurlencode("email:$email");
    $res = http_request('GET', $url, ['auth_user' => $user, 'auth_pass' => $pass]);
    if ($res['code'] >= 400) {
        throw new RuntimeException("Find contact failed (HTTP {$res['code']}): {$res['body']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) return null;

    // Responses often return "total" and "contacts" keyed by id
    if (!empty($json['total']) && !empty($json['contacts']) && is_array($json['contacts'])) {
        // Return the first contact id found
        $ids = array_keys($json['contacts']);
        if (!empty($ids)) return (int)$ids[0];
    }
    return null;
}

function split_name(?string $name): array
{
    $name = trim((string)$name);
    if ($name === '') return ['', ''];
    $parts = preg_split('/\s+/', $name);
    $first = array_shift($parts) ?? '';
    $last = implode(' ', $parts);
    return [$first, $last];
}

function mautic_api_create_or_update_contact(string $base, string $user, string $pass, string $email, ?string $name): int
{
    // POST /api/contacts/new  (Mautic will upsert by email by default if configured)
    $url = rtrim($base, '/') . '/api/contacts/new';
    [$first, $last] = split_name($name);

    $payload = [
        'email' => $email,
        'firstname' => $first,
        'lastname' => $last,
    ];

    $res = http_request('POST', $url, [
        'auth_user' => $user,
        'auth_pass' => $pass,
        'post_fields' => $payload, // x-www-form-urlencoded
    ]);

    if ($res['code'] >= 400) {
        throw new RuntimeException("Create/update contact failed (HTTP {$res['code']}): {$res['body']}");
    }

    $json = json_decode($res['body'], true);
    if (isset($json['contact']['id'])) {
        return (int)$json['contact']['id'];
    }
    // Some versions return 'contacts' keyed by id
    if (!empty($json['contacts']) && is_array($json['contacts'])) {
        $ids = array_keys($json['contacts']);
        if (!empty($ids)) return (int)$ids[0];
    }
    // As a fallback, re-query
    $found = mautic_api_find_contact_by_email($base, $user, $pass, $email);
    if ($found !== null) return $found;

    throw new RuntimeException("Unable to determine contact ID from response: {$res['body']}");
}

function mautic_api_add_contact_to_segment(string $base, string $user, string $pass, int $segmentId, int $contactId): bool
{
    // POST /api/segments/{id}/contact/{contactId}/add
    $url = rtrim($base, '/') . '/api/segments/' . $segmentId . '/contact/' . $contactId . '/add';
    $res = http_request('POST', $url, ['auth_user' => $user, 'auth_pass' => $pass]);
    if ($res['code'] >= 400) {
        throw new RuntimeException("Add to segment failed (HTTP {$res['code']}): {$res['body']}");
    }
    return true;
}

// ================== MAUTIC (FORM MODE) ==================
function mautic_form_submit(string $postUrl, int $formId, string $formAlias, array $fieldMap, array $row): bool
{
    // Build payload in the format mautic expects: mauticform[<alias>] = value
    $payload = [
        'mauticform[formId]' => (string)$formId,
        'mauticform[formName]' => $formAlias,
        'mauticform[return]' => '',
    ];
    foreach ($fieldMap as $formAliasField => $localCol) {
        $value = $row[$localCol] ?? '';
        $payload["mauticform[$formAliasField]"] = (string)$value;
    }
    // The embedded form usually sends submit button name; Mautic doesn’t require it here.
    $res = http_request('POST', $postUrl, ['post_fields' => $payload]);

    if ($res['code'] >= 400) {
        throw new RuntimeException("Form submit failed (HTTP {$res['code']}): {$res['body']}");
    }
    // Treat HTTP 200 as success. (If your form returns specific HTML/JSON on success, you can refine this.)
    return true;
}

// ================== MAIN ==================
// New email notification toggles
$ENABLE_EMAIL_ADDTOMAUTIC_SUCCESS = (int)(get_setting_value('enable_email_addtomautic_success') ?? 1);
$ENABLE_EMAIL_ADDTOMAUTIC_ERROR = (int)(get_setting_value('enable_email_addtomautic_error') ?? 1);

say("Starting Mautic import… Mode=" . MAUTIC_MODE);

try {
    $rows = fetch_pending_emails($BATCH_LIMIT);
} catch (Throwable $e) {
    err("DB fetch error: " . $e->getMessage());
    exit(1);
}

say("Found " . count($rows) . " pending emails.");

foreach ($rows as $row) {
    if ($successCount >= $limit_max) {
        say("Reached limit_max={$limit_max}. Stopping further processing.");
        break;
    }

    $id = (int)$row['id'];
    $email = trim((string)$row['email']);
    $name = $row['name'] ?? null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipCount++;
        say("Skip ID $id: invalid email '$email'.");
        continue;
    }

    try {
        if (MAUTIC_MODE === 'api') {
            global $MAUTIC_BASE_URL, $MAUTIC_USERNAME, $MAUTIC_PASSWORD, $MAUTIC_SEGMENT_ID;

            // 1) Find or create contact
            $contactId = mautic_api_find_contact_by_email($MAUTIC_BASE_URL, $MAUTIC_USERNAME, $MAUTIC_PASSWORD, $email);
            if ($contactId === null) {
                $contactId = mautic_api_create_or_update_contact($MAUTIC_BASE_URL, $MAUTIC_USERNAME, $MAUTIC_PASSWORD, $email, $name);
            }

            // 2) Add to segment
            mautic_api_add_contact_to_segment($MAUTIC_BASE_URL, $MAUTIC_USERNAME, $MAUTIC_PASSWORD, (int)$MAUTIC_SEGMENT_ID, $contactId);

        } elseif (MAUTIC_MODE === 'form') {
            global $MAUTIC_FORM_POST_URL, $MAUTIC_FORM_ID, $MAUTIC_FORM_NAME, $MAUTIC_FORM_FIELD_MAP;
            // Submit to form which has an action "Add to Segment"
            $ok = mautic_form_submit($MAUTIC_FORM_POST_URL, (int)$MAUTIC_FORM_ID, $MAUTIC_FORM_NAME, $MAUTIC_FORM_FIELD_MAP, $row);
        }
        // If we reached here without exception, mark as done
        mark_email_done($id);
        $successCount++;
        usleep($SLEEP_BETWEEN);

        if ($successCount >= $limit_max) {
            say("Reached limit_max={$limit_max}. Stopping further processing.");
            break;
        }

    } catch (Throwable $e) {
        err("ID $id failed: " . $e->getMessage());
        // do not mark as done; will retry next run
        continue;
    }
}

say("Completed. Success=$successCount, Skipped=$skipCount, Errors=" . count($errors) . ".");
if (!empty($errors)) {
    say("Some items failed; see above error messages.");
}

// Prepare email content for summary. Assume EMAIL_TO, EMAIL_FROM, EMAIL_SUBJ_PREFIX are set globally or retrieved again.
$EMAIL_TO = get_setting_value('email_to') ?? 'admin@example.com';
$EMAIL_FROM = get_setting_value('email_from') ?? 'no-reply@example.com';
$EMAIL_SUBJ_PREFIX = get_setting_value('email_subj_prefix') ?? 'Mautic Sync Report';

$subjectSuffix = (count($errors) > 0 ? 'Completed with Errors' : 'Success');
$subject = $EMAIL_SUBJ_PREFIX . ' - ' . $subjectSuffix . " (" . date('Y-m-d') . ")";

$bodyLines = [];
$bodyLines[] = "Mautic Sync Summary (" . date('Y-m-d H:i:s') . ")";
$bodyLines[] = "------------------------------------";
$bodyLines[] = "Total emails processed: " . (count($rows));
$bodyLines[] = "Successfully synced: " . $successCount;
$bodyLines[] = "Skipped (invalid format): " . $skipCount;
$bodyLines[] = "Errors: " . count($errors);
if (!empty($errors)) {
    $bodyLines[] = "\nDetails of Errors:";
    foreach ($errors as $e) {
        $bodyLines[] = "- " . $e;
    }
}
$bodyLines[] = "\nFull log available in application dashboard.";


$headers = [];
$headers[] = "From: {$EMAIL_FROM}";
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-Type: text/plain; charset=UTF-8";

// Check notification settings before sending
$shouldSendEmail = false;
if (!empty($EMAIL_TO)) {
    if (count($errors) > 0 && $ENABLE_EMAIL_ADDTOMAUTIC_ERROR === 1) {
        $shouldSendEmail = true; // Send error email if errors occurred and enabled
    } elseif (count($errors) === 0 && $ENABLE_EMAIL_ADDTOMAUTIC_SUCCESS === 1) {
        $shouldSendEmail = true; // Send success email if no errors occurred and enabled
    }
} else {
    err("Email not sent: Recipient address (email_to) is not configured in settings.");
}

if ($shouldSendEmail) {
    @mail($EMAIL_TO, $subject, implode("\n", $bodyLines), implode("\r\n", $headers));
}
