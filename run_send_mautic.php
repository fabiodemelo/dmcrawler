<?php
include 'auth_check.php';

exec("php " . __DIR__ . "/addtomautic.php > /dev/null 2>&1 &");

header('Location: index.php?started=send_emails_to_mautic');
exit;