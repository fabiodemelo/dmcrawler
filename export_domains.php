<?php
include 'auth_check.php';
include 'db.php';

// Build WHERE clause from filters (same logic as add_website.php)
$filterStatus = $_GET['status'] ?? 'all';
$filterCrawled = $_GET['crawled_filter'] ?? 'all';
$filterPriority = $_GET['priority_filter'] ?? 'all';
$filterDonot = $_GET['donot_filter'] ?? 'all';
$filterCampaign = $_GET['filter_campaign'] ?? '';
$filterKeyword = $_GET['filter_keyword'] ?? '';
$filterLocation = $_GET['filter_location'] ?? '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$whereParts = [];

if ($filterStatus === 'pending') $whereParts[] = 'd.crawled = 0';
elseif ($filterStatus === 'crawled') $whereParts[] = 'd.crawled = 1';

if ($filterCrawled === 'yes') $whereParts[] = 'd.date_crawled IS NOT NULL';
elseif ($filterCrawled === 'no') $whereParts[] = 'd.date_crawled IS NULL';

if ($filterPriority === 'high') $whereParts[] = 'd.priority = 1';
elseif ($filterPriority === 'normal') $whereParts[] = 'd.priority = 0';

if ($filterDonot === 'skip') $whereParts[] = 'd.donot = 1';
elseif ($filterDonot === 'crawl') $whereParts[] = 'd.donot = 0';

if ($filterCampaign !== '') $whereParts[] = "d.campaign_id = " . (int)$filterCampaign;
if ($filterKeyword !== '') {
    $ek = $conn->real_escape_string($filterKeyword);
    $whereParts[] = "d.source_keyword = '{$ek}'";
}
if ($filterLocation !== '') {
    $el = $conn->real_escape_string($filterLocation);
    $whereParts[] = "d.source_location = '{$el}'";
}
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $whereParts[] = "d.domain LIKE '%{$s}%'";
}

$whereClause = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=domains_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Domain', 'Status', 'Date Added', 'Date Crawled', 'URLs', 'Emails', 'Priority', 'DoNot', 'Campaign', 'Keyword', 'Location']);

$sql = "SELECT d.id, d.domain, d.crawled, d.date_added, d.date_crawled, d.urls_crawled, d.emails_found, d.priority, d.donot,
               c.name AS campaign_name, d.source_keyword, d.source_location
        FROM domains d
        LEFT JOIN campaigns c ON d.campaign_id = c.id
        {$whereClause}
        ORDER BY d.date_added DESC";

$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['id'],
            $r['domain'],
            $r['crawled'] ? 'Crawled' : 'Pending',
            $r['date_added'],
            $r['date_crawled'] ?? '',
            $r['urls_crawled'],
            $r['emails_found'],
            $r['priority'] ? 'High' : 'Normal',
            $r['donot'] ? 'Skip' : 'Crawl',
            $r['campaign_name'] ?? '',
            $r['source_keyword'] ?? '',
            $r['source_location'] ?? '',
        ]);
    }
}

fclose($out);
