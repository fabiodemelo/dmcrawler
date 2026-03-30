<?php
include 'auth_check.php';

exec("php " . __DIR__ . "/crawler.php --mode=email_extraction > /dev/null 2>&1 &");

header('Location: index.php?started=get_emails');
exit;