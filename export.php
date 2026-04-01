<?php
include 'auth_check.php';
include 'db.php';

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filterDomain = isset($_GET['filter_domain']) ? $conn->real_escape_string($_GET['filter_domain']) : '';
$filterMa = isset($_GET['filter_ma']) && in_array($_GET['filter_ma'], ['mautic', 'scheduled', 'failed']) ? $_GET['filter_ma'] : '';
$filterCampaign = isset($_GET['filter_campaign']) ? $conn->real_escape_string($_GET['filter_campaign']) : '';
$filterKeyword = isset($_GET['filter_keyword']) ? $conn->real_escape_string($_GET['filter_keyword']) : '';
$filterLocation = isset($_GET['filter_location']) ? $conn->real_escape_string($_GET['filter_location']) : '';

$whereClauses = [];

if (!empty($search)) {
    $whereClauses[] = "(e.email LIKE '%{$search}%' OR d.domain LIKE '%{$search}%' OR e.name LIKE '%{$search}%')";
}

switch ($filter) {
    case 'today': $whereClauses[] = 'DATE(e.created_at) = CURDATE()'; break;
    case 'week': $whereClauses[] = 'YEARWEEK(e.created_at, 1) = YEARWEEK(CURDATE(), 1)'; break;
    case 'month': $whereClauses[] = 'MONTH(e.created_at) = MONTH(CURDATE()) AND YEAR(e.created_at) = YEAR(CURDATE())'; break;
    case 'year': $whereClauses[] = 'YEAR(e.created_at) = YEAR(CURDATE())'; break;
}

if ($filterDomain !== '') $whereClauses[] = "d.domain = '{$filterDomain}'";
if ($filterMa === 'mautic') $whereClauses[] = "e.ma IS NOT NULL AND e.ma > 0";
elseif ($filterMa === 'scheduled') $whereClauses[] = "(e.ma IS NULL OR e.ma = 0)";
elseif ($filterMa === 'failed') $whereClauses[] = "e.ma < 0";
if ($filterCampaign !== '') $whereClauses[] = "e.campaign_id = '{$filterCampaign}'";
if ($filterKeyword !== '') $whereClauses[] = "e.source_keyword = '{$filterKeyword}'";
if ($filterLocation !== '') $whereClauses[] = "e.source_location = '{$filterLocation}'";

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=emails_' . $filter . '_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['Domain', 'Name', 'Email', 'Date Found', 'Mautic Status', 'Campaign', 'Keyword', 'Location']);

$query = "SELECT d.domain, e.name, e.email, e.created_at, e.ma, c.name AS campaign_name, e.source_keyword, e.source_location
          FROM emails e
          JOIN domains d ON e.domain_id = d.id
          LEFT JOIN campaigns c ON e.campaign_id = c.id
          {$whereSql}
          ORDER BY e.created_at DESC";

$res = $conn->query($query);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $maStatus = 'Pending';
        if ($r['ma'] !== null && (int)$r['ma'] > 0) $maStatus = 'Synced';
        elseif ($r['ma'] !== null && (int)$r['ma'] < 0) $maStatus = 'Failed';

        fputcsv($out, [
            $r['domain'],
            $r['name'],
            $r['email'],
            $r['created_at'],
            $maStatus,
            $r['campaign_name'] ?? '',
            $r['source_keyword'] ?? '',
            $r['source_location'] ?? '',
        ]);
    }
}

fclose($out);
