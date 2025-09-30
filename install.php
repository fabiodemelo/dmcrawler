<?php
// version 3.5
// This script sets up the entire database structure for a fresh installation.
include 'db.php';

echo "Creating tables...\n";


// Domains Table
$conn->query("CREATE TABLE IF NOT EXISTS domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(255) UNIQUE,
  crawled BOOLEAN DEFAULT 0,
  date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  date_crawled TIMESTAMP NULL,
  emails_found INT(11) NOT NULL DEFAULT 0,
  urls_crawled INT(11) NOT NULL DEFAULT 0
)");
echo "Table 'domains' created or already exists.\n";

// Emails Table
$conn->query("CREATE TABLE IF NOT EXISTS emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT,
  name VARCHAR(255),
  email VARCHAR(255) UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
)");
echo "Table 'emails' created or already exists.\n";

// Settings Table
$conn->query("CREATE TABLE IF NOT EXISTS settings (
  id INT PRIMARY KEY,
  page_delay_min INT,
  page_delay_max INT,
  domain_delay_min INT,
  domain_delay_max INT,
  max_depth INT DEFAULT 20
)");
echo "Table 'settings' created or already exists.\n";

// Users Table
$conn->query("CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
)");
echo "Table 'users' created or already exists.\n";

// Password Resets Table
$conn->query("CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires` bigint(20) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `email` (`email`)
)");
echo "Table 'password_resets' created or already exists.\n";


echo "\nInserting default data...\n";

// Default Settings
$conn->query("INSERT IGNORE INTO settings (id, page_delay_min, page_delay_max, domain_delay_min, domain_delay_max, max_depth)
VALUES (1, 2, 5, 1, 3, 20)");
echo "Default settings inserted or already exist.\n";

// Default User
$default_user = 'fabio@demelos.com';
$default_pass = 'Roberto24@';
$hashed_password = password_hash($default_pass, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT IGNORE INTO users (id, username, password) VALUES (1, ?, ?)");
$stmt->bind_param("ss", $default_user, $hashed_password);
$stmt->execute();
echo "Default user inserted or already exists.\n";

echo "\nInstallation complete.\n";

?>
