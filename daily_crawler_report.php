<?php
declare(strict_types=1);

/**
 * daily_crawler_report.php
 * Nightly email report (HTML) with dashboard-style cards + two tables:
 *  1) Domains crawled today
 *  2) Emails collected today
 *
 * Run:
 *   /usr/bin/php /var/www/html/demelos/daily_crawler_report.php
 */

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Los_Angeles'); // change if needed

// Read email settings from database (fallback to defaults)
$TO   = get_setting_value('email_to') ?? 'fabio@demelos.com';
$FROM = get_setting_value('email_from') ?? 'no-reply@demelos.com';

// Check if daily report is enabled (default: enabled)
$enableDailyReport = (int)(get_setting_value('enable_daily_report') ?? 1);
if (!$enableDailyReport) {
    if (php_sapi_name() === 'cli') echo "Daily report is disabled in settings. Exiting.\n";
    exit(0);
}

$MAX_DOMAINS_TABLE_ROWS = 250; // prevent massive emails
$MAX_EMAILS_TABLE_ROWS  = 500;

function html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function qval(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_row();
    return $row ? (int)$row[0] : 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $tableEsc = $conn->real_escape_string($table);
    $colEsc   = $conn->real_escape_string($column);
    $sql = "
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$tableEsc}'
          AND COLUMN_NAME = '{$colEsc}'
        LIMIT 1
    ";
    return qval($conn, $sql) > 0;
}

/**
 * Fetch rows safely into array (associative).
 */
function fetchAll(mysqli $conn, string $sql): array {
    $out = [];
    $res = $conn->query($sql);
    if (!$res) return $out;
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    return $out;
}

$todayYmd = date('Y-m-d');

// --- Summary counts ---
$totalDomains = qval($conn, "SELECT COUNT(*) FROM domains");

$crawledToday = qval(
    $conn,
    "SELECT COUNT(*)
     FROM domains
     WHERE date_crawled >= CURDATE()
       AND date_crawled < (CURDATE() + INTERVAL 1 DAY)"
);

// --- Detect emails date column for "emails collected today" ---
$emailDateCol = null;
if (hasColumn($conn, 'emails', 'created_at')) $emailDateCol = 'created_at';
elseif (hasColumn($conn, 'emails', 'date_added')) $emailDateCol = 'date_added';
elseif (hasColumn($conn, 'emails', 'date_found')) $emailDateCol = 'date_found';

$emailsTodayCount = 0;
if ($emailDateCol !== null) {
    $emailsTodayCount = qval(
        $conn,
        "SELECT COUNT(*)
         FROM emails
         WHERE {$emailDateCol} >= CURDATE()
           AND {$emailDateCol} < (CURDATE() + INTERVAL 1 DAY)
           AND email IS NOT NULL AND email <> ''"
    );
} else {
    // Fallback (not perfect, but at least shows something)
    $emailsTodayCount = qval(
        $conn,
        "SELECT COALESCE(SUM(emails_found), 0)
         FROM domains
         WHERE date_crawled >= CURDATE()
           AND date_crawled < (CURDATE() + INTERVAL 1 DAY)"
    );
}

// --- Table 1: Domains crawled today ---
$domainsRows = fetchAll(
    $conn,
    "SELECT id, domain, date_crawled, emails_found, urls_crawled
     FROM domains
     WHERE date_crawled >= CURDATE()
       AND date_crawled < (CURDATE() + INTERVAL 1 DAY)
     ORDER BY date_crawled DESC
     LIMIT " . (int)$MAX_DOMAINS_TABLE_ROWS
);

$domainsTotalForDay = $crawledToday;
$domainsTruncated = ($domainsTotalForDay > count($domainsRows));

// --- Table 2: Email addresses collected today ---
$emailsRows = [];
$emailsTotalForDay = $emailsTodayCount;
$emailsTruncated = false;

if ($emailDateCol !== null) {
    // If your emails table has name/domain_id, show them if present.
    $hasName = hasColumn($conn, 'emails', 'name');
    $hasDomainId = hasColumn($conn, 'emails', 'domain_id');

    $selectCols = "id, email, {$emailDateCol} AS collected_at";
    if ($hasName) $selectCols .= ", name";
    if ($hasDomainId) $selectCols .= ", domain_id";

    $emailsRows = fetchAll(
        $conn,
        "SELECT {$selectCols}
         FROM emails
         WHERE {$emailDateCol} >= CURDATE()
           AND {$emailDateCol} < (CURDATE() + INTERVAL 1 DAY)
           AND email IS NOT NULL AND email <> ''
         ORDER BY {$emailDateCol} DESC
         LIMIT " . (int)$MAX_EMAILS_TABLE_ROWS
    );

    $emailsTruncated = ($emailsTotalForDay > count($emailsRows));
}

// --- Build HTML email ---
$health = 'OK';
$healthNote = 'Crawler appears to be running.';
if ($crawledToday === 0) {
    $health = 'CHECK';
    $healthNote = 'No domains crawled today.';
}

$subject = "## Crawler Nightly Report - {$todayYmd} | Domains: {$crawledToday} | Emails: {$emailsTodayCount}";

$card = function(string $title, string $value, string $subtitle, string $bg, string $border, string $accent) {
    return '
    <div style="flex:1; min-width:220px; background:'.$bg.'; border:1px solid '.$border.'; border-radius:12px; padding:14px 16px;">
      <div style="font-size:12px; letter-spacing:.4px; text-transform:uppercase; color:#94a3b8;">'.html($title).'</div>
      <div style="font-size:30px; font-weight:800; color:#e2e8f0; margin-top:6px;">'.html($value).'</div>
      <div style="font-size:12px; color:'.$accent.'; margin-top:6px;">'.html($subtitle).'</div>
    </div>';
};

function renderTable(string $title, array $headers, array $rows, bool $truncated, string $truncMsg): string {
    $thead = '';
    foreach ($headers as $h) {
        $thead .= '<th align="left" style="padding:10px; color:#94a3b8; font-weight:700; border-bottom:1px solid #1f2937;">'.html($h).'</th>';
    }

    $tbody = '';
    if (empty($rows)) {
        $tbody .= '<tr><td colspan="'.count($headers).'" style="padding:10px; color:#cbd5e1;">(No rows)</td></tr>';
    } else {
        foreach ($rows as $r) {
            $tbody .= '<tr>';
            foreach (array_keys($headers) as $k) {
                $val = isset($r[$k]) ? (string)$r[$k] : '';
                $tbody .= '<td style="padding:10px; border-top:1px solid #1f2937; color:#e2e8f0; vertical-align:top;">'.html($val).'</td>';
            }
            $tbody .= '</tr>';
        }
    }

    $note = '';
    if ($truncated) {
        $note = '<div style="padding:10px 14px; color:#fbbf24; font-size:12px; border-top:1px solid #1f2937;">'.html($truncMsg).'</div>';
    }

    return '
      <div style="margin-top:18px; background:#0f172a; border:1px solid #1f2937; border-radius:12px; overflow:hidden;">
        <div style="padding:12px 14px; color:#e2e8f0; font-weight:800;">'.html($title).'</div>
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead><tr>'.$thead.'</tr></thead>
          <tbody>'.$tbody.'</tbody>
        </table>
        '.$note.'
      </div>
    ';
}

// Prepare domain table rows (normalize keys to match renderTable mapping)
$domainsTableRows = [];
foreach ($domainsRows as $r) {
    $domainsTableRows[] = [
        'domain' => (string)($r['domain'] ?? ''),
        'date_crawled' => (string)($r['date_crawled'] ?? ''),
        'emails_found' => (string)($r['emails_found'] ?? ''),
        'urls_crawled' => (string)($r['urls_crawled'] ?? ''),
    ];
}

// Prepare emails table rows
$emailsTableRows = [];
if ($emailDateCol !== null) {
    foreach ($emailsRows as $r) {
        // Try to enrich with domain when domain_id exists
        $domainTxt = '';
        if (isset($r['domain_id']) && is_numeric($r['domain_id'])) {
            $did = (int)$r['domain_id'];
            $dRes = $conn->query("SELECT domain FROM domains WHERE id = {$did} LIMIT 1");
            if ($dRes && ($dRow = $dRes->fetch_assoc())) {
                $domainTxt = (string)$dRow['domain'];
            }
        }

        $emailsTableRows[] = [
            'email' => (string)($r['email'] ?? ''),
            'name' => (string)($r['name'] ?? ''),
            'domain' => $domainTxt,
            'collected_at' => (string)($r['collected_at'] ?? ''),
        ];
    }
}

$emailsSectionExtra = '';
if ($emailDateCol === null) {
    $emailsSectionExtra = '<div style="margin-top:10px; color:#fbbf24; font-size:12px;">
      Note: Your <b>emails</b> table does not have a date column (created_at/date_added/date_found),
      so the report cannot list “emails collected today” precisely. Add one of those columns to enable the table.
    </div>';
}

$body = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:0; background:#0b1220;">
  <div style="max-width:980px; margin:0 auto; padding:22px;">
    <div style="color:#e2e8f0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;">
      <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap;">
        <div>
          <div style="font-size:18px; font-weight:900;">Crawler Nightly Report</div>
          <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Date: '.html($todayYmd).' | Health: <span style="color:#fbbf24; font-weight:800;">'.html($health).'</span></div>
        </div>
        <div style="font-size:12px; color:#94a3b8;">'.html($healthNote).'</div>
      </div>

      <div style="display:flex; gap:12px; margin-top:16px; flex-wrap:wrap;">
        '.$card('Total Domains', (string)$totalDomains, 'All domains in database', '#0f172a', '#1f2937', '#a7f3d0').'
        '.$card('Domains Crawled Today', (string)$crawledToday, 'Crawled since midnight', '#111827', '#1f2937', '#93c5fd').'
        '.$card('Emails Collected Today', (string)$emailsTodayCount, 'Collected since midnight', '#052e2b', '#0f766e', '#99f6e4').'
      </div>

      '.renderTable(
        'Domains crawled today',
        ['domain' => 'Domain', 'date_crawled' => 'Crawled At', 'emails_found' => 'Emails Found', 'urls_crawled' => 'URLs Crawled'],
        $domainsTableRows,
        $domainsTruncated,
        'Truncated: showing only the first '.$MAX_DOMAINS_TABLE_ROWS.' rows. Total crawled today: '.$domainsTotalForDay.'.'
    ).'

      '.renderTable(
        'Email addresses collected today',
        ['email' => 'Email', 'name' => 'Name', 'domain' => 'Domain', 'collected_at' => 'Collected At'],
        $emailsTableRows,
        $emailsTruncated,
        'Truncated: showing only the first '.$MAX_EMAILS_TABLE_ROWS.' rows. Total emails today: '.$emailsTotalForDay.'.'
    ).'

      '.$emailsSectionExtra.'

      <div style="margin-top:14px; font-size:12px; color:#94a3b8;">
        This email is generated automatically at 8:30 PM.
      </div>
    </div>
  </div>
</body>
</html>
';

$headers = [];
$headers[] = "From: {$FROM}";
$headers[] = "Reply-To: {$FROM}";
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-Type: text/html; charset=UTF-8";

$ok = @mail($TO, $subject, $body, implode("\r\n", $headers));

if (php_sapi_name() === 'cli') {
    echo $ok ? "OK: report emailed to {$TO}\n" : "ERROR: mail() failed\n";
}

exit($ok ? 0 : 1);