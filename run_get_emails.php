<?php
// This script starts crawler.php for email extraction in the background
// Ensure the full path to PHP and the script is correct for your server environment.
$php_executable = '/usr/bin/php';
$script_path = '/var/www/html/demelos/crawler.php'; // Path to your actual crawler.php

// Execute the script in the background. "> /dev/null 2>&1" redirects all output to null. "&" puts it in the background.
// Note: If you have different logic for "Get Emails" vs "Run Crawler",
// you might need a separate script or parameter for crawler.php to distinguish.
// For now, it runs crawler.php which should handle both domain and email crawling.
exec("{$php_executable} {$script_path} --mode=email_extraction > /dev/null 2>&1 &");

header('Location: index.php?started=get_emails'); // Redirect back to index with a status message
exit;
?>