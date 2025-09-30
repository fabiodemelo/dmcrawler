<?php
// version 3.4 - Logout
session_start();
$_SESSION = array();
session_destroy();
header("location: login.php");
exit;
?>
