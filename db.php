<?php
// Load environment variables from .env file
$_envFile = __DIR__ . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strpos(trim($_line), '#') === 0) continue;
        if (strpos($_line, '=') !== false) {
            [$_key, $_val] = explode('=', $_line, 2);
            $_ENV[trim($_key)] = trim($_val);
        }
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'demelos';
$user = $_ENV['DB_USER'] ?? 'fabio';
$pass = $_ENV['DB_PASS'] ?? '';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Shared utility functions (loaded once for all scripts)
$_includes_path = __DIR__ . '/includes/functions.php';
if (file_exists($_includes_path)) {
    require_once $_includes_path;
}
