<?php
/**
 * Phase 2 Database Migration
 * Safe to run multiple times — uses IF NOT EXISTS and column checks.
 * Run via browser or CLI: php migrate_phase2.php
 */
include __DIR__ . '/db.php';
// functions.php is already loaded via db.php

$results = [];

function run_migration($sql, $label) {
    global $conn, $results;
    if ($conn->query($sql)) {
        $results[] = "OK: {$label}";
    } else {
        $results[] = "ERR: {$label} — " . $conn->error;
    }
}

function add_column_if_missing($table, $column, $definition) {
    global $conn, $results;
    if (!column_exists($table, $column)) {
        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";
        if ($conn->query($sql)) {
            $results[] = "OK: Added {$table}.{$column}";
        } else {
            $results[] = "ERR: Adding {$table}.{$column} — " . $conn->error;
        }
    } else {
        $results[] = "SKIP: {$table}.{$column} already exists";
    }
}

echo php_sapi_name() === 'cli' ? "" : "<pre>";
echo "=== Phase 2 Migration ===\n\n";

// ─── 1. ALTER domains table ───
add_column_if_missing('domains', 'quality_score', 'TINYINT DEFAULT NULL');
add_column_if_missing('domains', 'quality_tier', "ENUM('high','medium','low','spam') DEFAULT NULL");
add_column_if_missing('domains', 'max_pages_budget', 'INT DEFAULT NULL');
add_column_if_missing('domains', 'total_valid_emails', 'INT DEFAULT 0');
add_column_if_missing('domains', 'total_rejected_emails', 'INT DEFAULT 0');
add_column_if_missing('domains', 'blacklisted', 'TINYINT(1) DEFAULT 0');
add_column_if_missing('domains', 'blacklist_reason', 'VARCHAR(255) DEFAULT NULL');

// ─── 2. ALTER emails table ───
add_column_if_missing('emails', 'confidence_score', 'TINYINT DEFAULT NULL');
add_column_if_missing('emails', 'confidence_tier', "ENUM('high','acceptable','low','rejected') DEFAULT NULL");
add_column_if_missing('emails', 'rejection_reason', 'VARCHAR(255) DEFAULT NULL');
add_column_if_missing('emails', 'page_url', 'VARCHAR(2048) DEFAULT NULL');
add_column_if_missing('emails', 'page_quality_score', 'TINYINT DEFAULT NULL');

// ─── 3. CREATE crawl_pages table ───
run_migration("CREATE TABLE IF NOT EXISTS `crawl_pages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `domain_id` INT NOT NULL,
    `url` VARCHAR(2048) NOT NULL,
    `quality_score` TINYINT DEFAULT NULL,
    `emails_extracted` INT DEFAULT 0,
    `emails_accepted` INT DEFAULT 0,
    `emails_rejected` INT DEFAULT 0,
    `http_status` INT DEFAULT NULL,
    `crawled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_domain_id` (`domain_id`),
    INDEX `idx_quality_score` (`quality_score`),
    FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create crawl_pages table');

// ─── 4. CREATE email_rejections table ───
run_migration("CREATE TABLE IF NOT EXISTS `email_rejections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `domain_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `page_url` VARCHAR(2048) DEFAULT NULL,
    `rejection_reason` VARCHAR(255) NOT NULL,
    `rejection_category` ENUM('syntax','disposable','random_pattern','low_quality_page','duplicate','no_business_relevance','spam','trap_name','bad_extension','bad_tld','too_long') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_domain_id` (`domain_id`),
    INDEX `idx_category` (`rejection_category`),
    FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create email_rejections table');

// ─── 5. CREATE crawl_metrics table ───
run_migration("CREATE TABLE IF NOT EXISTS `crawl_metrics` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `domain_id` INT NOT NULL,
    `run_started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `run_ended_at` TIMESTAMP NULL,
    `pages_crawled` INT DEFAULT 0,
    `valid_emails` INT DEFAULT 0,
    `rejected_emails` INT DEFAULT 0,
    `pages_per_valid_email` DECIMAL(8,2) DEFAULT NULL,
    `api_calls` INT DEFAULT 0,
    `total_time_seconds` INT DEFAULT 0,
    `stop_reason` VARCHAR(255) DEFAULT NULL,
    INDEX `idx_domain_id` (`domain_id`),
    INDEX `idx_started` (`run_started_at`),
    FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create crawl_metrics table');

// ─── 6. CREATE disposable_domains table ───
run_migration("CREATE TABLE IF NOT EXISTS `disposable_domains` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `domain` VARCHAR(255) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'Create disposable_domains table');

// Seed disposable domains
$disposable = [
    'mailinator.com','guerrillamail.com','guerrillamail.info','grr.la','guerrillamail.biz',
    'guerrillamail.de','guerrillamail.net','guerrillamail.org','tempmail.com','temp-mail.org',
    'throwaway.email','fakeinbox.com','sharklasers.com','guerrillamailblock.com','pokemail.net',
    'spam4.me','binkmail.com','bobmail.info','chammy.info','devnullmail.com',
    'dispostable.com','duam.net','einrot.com','emailigo.de','emailsensei.com',
    'ephemail.net','filzmail.com','getairmail.com','gishpuppy.com','harakirimail.com',
    'imstations.com','inbucket.com','ipoo.org','jetable.com','jetable.fr.nf',
    'jetable.net','jetable.org','kasmail.com','koszmail.pl','kurzepost.de',
    'mailcatch.com','maildrop.cc','mailexpire.com','mailforspam.com','mailhazard.com',
    'mailhazard.us','mailhz.me','mailimate.com','mailin8r.com','mailinator.net',
    'mailinator2.com','mailincubator.com','mailme.ir','mailme.lv','mailmetrash.com',
    'mailmoat.com','mailnesia.com','mailnull.com','mailshell.com','mailsiphon.com',
    'mailtemp.info','mailtothis.com','mailzilla.com','makemetheking.com','mezimages.net',
    'mfsa.ru','mintemail.com','mt2015.com','mytemp.email','mytrashmail.com',
    'nobulk.com','noclickemail.com','nogmailspam.info','nomail.xl.cx','nomail2me.com',
    'nospam.ze.tc','nospamfor.us','nothingtoseehere.ca','nowmymail.com','objectmail.com',
    'obobbo.com','onewaymail.com','owlpic.com','pjjkp.com','plexolan.de',
    'pookmail.com','proxymail.eu','rcpt.at','reallymymail.com','recode.me',
    'regbypass.com','rmqkr.net','royal.net','safersignup.de','safetymail.info',
    'sandelf.de','saynotospams.com','scatmail.com','schafmail.de','selfdestructingmail.com',
    'shieldedmail.com','singletrail.net','slaskpost.se','slipry.net','sofimail.com',
    'sogetthis.com','soodonims.com','spam.la','spamavert.com','spambob.net',
    'spambob.org','spambog.com','spambog.de','spambog.ru','spamcero.com',
    'spamday.com','spamex.com','spamfighter.cf','spamfighter.ga','spamfighter.gq',
    'spamfighter.ml','spamfighter.tk','spamfree24.com','spamfree24.de','spamfree24.eu',
    'spamfree24.info','spamfree24.net','spamfree24.org','spamgourmet.com','spamgourmet.net',
    'spamgourmet.org','spamherelots.com','spamhereplease.com','spamhole.com','spamify.com',
    'spaminator.de','spamkill.info','spaml.com','spaml.de','spammotel.com',
    'spamobox.com','spamoff.de','spamslicer.com','spamspot.com','spamstack.net',
    'spamtrail.com','speed.1s.fr','superrito.com','suremail.info','teleworm.us',
    'tempalias.com','tempe4mail.com','tempemail.biz','tempemail.co.za','tempemail.com',
    'tempemail.net','tempinbox.com','tempinbox.co.uk','tempmail.eu','tempmail.it',
    'tempmail2.com','tempmaildemo.com','tempmailer.com','tempmailer.de','tempomail.fr',
    'temporarily.de','temporarioemail.com.br','temporaryemail.net','temporaryemail.us',
    'temporaryforwarding.com','temporaryinbox.com','temporarymailaddress.com','thankyou2010.com',
    'thisisnotmyrealemail.com','throwawayemailaddress.com','tittbit.in','tradermail.info',
    'trash-amil.com','trash-mail.at','trash-mail.com','trash-mail.de','trash2009.com',
    'trashemail.de','trashmail.at','trashmail.com','trashmail.de','trashmail.io',
    'trashmail.me','trashmail.net','trashmail.org','trashmail.ws','trashmailer.com',
    'trashymail.com','trashymail.net','turual.com','twinmail.de','tyldd.com',
    'uggsrock.com','upliftnow.com','uplipht.com','venompen.com','veryreallymymail.com',
    'viditag.com','viewcastmedia.com','viewcastmedia.net','viewcastmedia.org',
    'wegwerfadresse.de','wegwerfemail.com','wegwerfemail.de','wegwerfmail.de','wegwerfmail.net',
    'wegwerfmail.org','wh4f.org','whyspam.me','wickmail.net','wilemail.com',
    'willhackforfood.biz','willselfdestruct.com','winemaven.info','wronghead.com',
    'wuzup.net','wuzupmail.net','wwwnew.eu','xagloo.com','xemaps.com',
    'xents.com','xjoi.com','xmaily.com','xoxy.net','yapped.net',
    'yep.it','yogamaven.com','yopmail.com','yopmail.fr','yuurok.com',
    'zehnminutenmail.de','zippymail.info','zoaxe.com','zoemail.org',
    '10minutemail.com','20minutemail.com','33mail.com','mailnator.com',
    'yopmail.net','tempail.com','mohmal.com','getnada.com','emailondeck.com',
    'burnermail.io','discard.email','mailsac.com','inboxbear.com','tempinbox.xyz',
];

$insertCount = 0;
$stmt = $conn->prepare("INSERT IGNORE INTO disposable_domains (domain) VALUES (?)");
foreach ($disposable as $d) {
    $stmt->bind_param('s', $d);
    $stmt->execute();
    if ($stmt->affected_rows > 0) $insertCount++;
}
$stmt->close();
$results[] = "OK: Seeded {$insertCount} new disposable domains (total list: " . count($disposable) . ")";

// ─── 7. ALTER settings table — Phase 2 config ───
add_column_if_missing('settings', 'page_quality_threshold', 'INT DEFAULT 30');
add_column_if_missing('settings', 'email_confidence_threshold', 'INT DEFAULT 40');
add_column_if_missing('settings', 'max_fake_ratio', 'DECIMAL(3,2) DEFAULT 0.60');
add_column_if_missing('settings', 'max_consecutive_low_pages', 'INT DEFAULT 3');
add_column_if_missing('settings', 'domain_quality_enabled', 'TINYINT(1) DEFAULT 1');

// ─── Output Results ───
echo "\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\n=== Migration complete ===\n";
echo php_sapi_name() === 'cli' ? "" : "</pre>";

$conn->close();
