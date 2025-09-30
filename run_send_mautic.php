<?php
// This script starts addtomautic.php in the background
// Ensure the full path to PHP and the script is correct for your server environment.
$php_executable = '/usr/bin/php';
$script_path = '/var/www/html/demelos/addtomautic.php'; // Path to your actual addtomautic.php

// Execute the script in the background. "> /dev/null 2>&1" redirects all output to null. "&" puts it in the background.
exec("{$php_executable} {$script_path} > /dev/null 2>&1 &");

header('Location: index.php?started=send_emails_to_mautic'); // Redirect back to index with a status message
exit;
?>