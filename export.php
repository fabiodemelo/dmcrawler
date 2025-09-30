<?php
//version 3.3
include 'db.php';

// Define the current filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build the WHERE clause based on the filter
$whereClause = '';
switch ($filter) {
    case 'today':
        $whereClause = 'WHERE DATE(e.created_at) = CURDATE()';
        break;
    case 'week':
        $whereClause = 'WHERE YEARWEEK(e.created_at, 1) = YEARWEEK(CURDATE(), 1)';
        break;
    case 'month':
        $whereClause = 'WHERE MONTH(e.created_at) = MONTH(CURDATE()) AND YEAR(e.created_at) = YEAR(CURDATE())';
        break;
    case 'year':
        $whereClause = 'WHERE YEAR(e.created_at) = YEAR(CURDATE())';
        break;
    case 'all':
    default:
        $whereClause = '';
        break;
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename=emails_' . $filter . '_' . date('Y-m-d') . '.csv');

// Open output stream
$out = fopen('php://output', 'w');

// Add CSV header
fputcsv($out, ['Domain', 'Name', 'Email', 'Date Found']);

// Fetch emails from the database with the filter
$query = "SELECT d.domain, e.name, e.email, e.created_at FROM emails e JOIN domains d ON e.domain_id = d.id $whereClause ORDER BY e.created_at DESC";
$res = $conn->query($query);

// Write rows to the CSV
if ($res) {
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['domain'], $r['name'], $r['email'], $r['created_at']]);
    }
}

fclose($out);
?>
