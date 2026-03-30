<?php
include 'auth_check.php';

exec("php " . __DIR__ . "/getURLS.php > /dev/null 2>&1 &");

header('Location: index.php?started=get_urls');
exit;
