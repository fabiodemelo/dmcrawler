<?php
/**
 * migrate_campaigns.php
 * Adds campaign tracking to the crawler system.
 * Safe to run multiple times — all operations use IF NOT EXISTS / column checks.
 *
 * Run:  php migrate_campaigns.php
 *   or visit in browser (requires auth)
 */

if (php_sapi_name() !== 'cli') {
    include __DIR__ . '/auth_check.php';
}
include __DIR__ . '/db.php';

$out = function(string $msg) {
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
};

function col_exists(mysqli $conn, string $table, string $col): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $r = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}' LIMIT 1");
    return $r && $r->num_rows > 0;
}

$out("=== Campaign Migration ===");

// 1. Create campaigns table
$conn->query("CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=active, 0=inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$out("Table 'campaigns' created or already exists.");

// 2. Insert default "General" campaign if empty
$res = $conn->query("SELECT COUNT(*) AS cnt FROM campaigns");
$cnt = $res ? (int)$res->fetch_assoc()['cnt'] : 0;
if ($cnt === 0) {
    $conn->query("INSERT INTO campaigns (name, status) VALUES ('General', 1)");
    $out("Default 'General' campaign created and set as active.");
} else {
    $out("Campaigns table already has data — skipping seed.");
}

// 3. Add campaign_id, source_keyword, source_location to domains
if (!col_exists($conn, 'domains', 'campaign_id')) {
    $conn->query("ALTER TABLE domains ADD COLUMN campaign_id INT NULL AFTER urls_crawled");
    $out("Added domains.campaign_id");
} else {
    $out("domains.campaign_id already exists.");
}

if (!col_exists($conn, 'domains', 'source_keyword')) {
    $conn->query("ALTER TABLE domains ADD COLUMN source_keyword VARCHAR(255) NULL AFTER campaign_id");
    $out("Added domains.source_keyword");
} else {
    $out("domains.source_keyword already exists.");
}

if (!col_exists($conn, 'domains', 'source_location')) {
    $conn->query("ALTER TABLE domains ADD COLUMN source_location VARCHAR(255) NULL AFTER source_keyword");
    $out("Added domains.source_location");
} else {
    $out("domains.source_location already exists.");
}

// 4. Add campaign_id, source_keyword, source_location to emails
if (!col_exists($conn, 'emails', 'campaign_id')) {
    $conn->query("ALTER TABLE emails ADD COLUMN campaign_id INT NULL AFTER page_quality_score");
    $out("Added emails.campaign_id");
} else {
    $out("emails.campaign_id already exists.");
}

if (!col_exists($conn, 'emails', 'source_keyword')) {
    $conn->query("ALTER TABLE emails ADD COLUMN source_keyword VARCHAR(255) NULL AFTER campaign_id");
    $out("Added emails.source_keyword");
} else {
    $out("emails.source_keyword already exists.");
}

if (!col_exists($conn, 'emails', 'source_location')) {
    $conn->query("ALTER TABLE emails ADD COLUMN source_location VARCHAR(255) NULL AFTER source_keyword");
    $out("Added emails.source_location");
} else {
    $out("emails.source_location already exists.");
}

// 5. Set existing data to "General" campaign
$genRes = $conn->query("SELECT id FROM campaigns WHERE name = 'General' LIMIT 1");
if ($genRes && ($genRow = $genRes->fetch_assoc())) {
    $genId = (int)$genRow['id'];
    $conn->query("UPDATE domains SET campaign_id = {$genId} WHERE campaign_id IS NULL");
    $affected1 = $conn->affected_rows;
    $conn->query("UPDATE emails SET campaign_id = {$genId} WHERE campaign_id IS NULL");
    $affected2 = $conn->affected_rows;
    $out("Assigned 'General' campaign to {$affected1} domains and {$affected2} emails.");
}

// 6. Add indexes
$conn->query("ALTER TABLE domains ADD INDEX idx_campaign (campaign_id)");
$conn->query("ALTER TABLE emails ADD INDEX idx_email_campaign (campaign_id)");
$out("Indexes added (or already existed).");

$out("\n=== Migration complete ===");

$conn->close();
