<?php
$host = 'localhost';
$db = 'demelos';
$user = 'fabio';
$pass = 'symRoberto24@';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
?>
