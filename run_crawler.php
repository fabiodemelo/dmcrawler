<?php
include 'auth_check.php';

// Support crawling a specific domain by ID
$domain_id = isset($_GET['domain_id']) ? (int)$_GET['domain_id'] : 0;

if ($domain_id > 0) {
    // Crawl a specific domain
    exec("php " . __DIR__ . "/crawler.php --domain=" . $domain_id . " > /dev/null 2>&1 &");
    header('Location: add_website.php?msg_type=success&msg_text=' . urlencode("Crawler started for domain ID $domain_id"));
} else {
    // Crawl next in queue (default behavior)
    exec("php " . __DIR__ . "/crawler.php > /dev/null 2>&1 &");
    header('Location: index.php?started=crawler');
}
exit;
