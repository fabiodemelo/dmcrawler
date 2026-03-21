<?php
/**
 * Shared utility functions — included automatically via db.php.
 * Replaces duplicated function definitions across crawler.php, getURLS.php, addtomautic.php, etc.
 */

if (!function_exists('ensure_db_connection')) {
    function ensure_db_connection() {
        global $conn;
        if ($conn instanceof mysqli && @$conn->ping()) {
            return true;
        }
        $dbPath = __DIR__ . '/../db.php';
        if (file_exists($dbPath)) {
            include $dbPath;
        }
        return ($conn instanceof mysqli && @$conn->ping());
    }
}

if (!function_exists('get_setting_value')) {
    function get_setting_value($field) {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            return null;
        }
        if (!ensure_db_connection()) {
            return null;
        }
        global $conn;
        $res = $conn->query("SELECT `{$field}` AS v FROM settings WHERE id = 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return $row['v'];
        }
        return null;
    }
}

if (!function_exists('force_flush')) {
    function force_flush() {
        if (php_sapi_name() !== 'cli') {
            echo "<!-- FLUSH " . microtime(true) . " -->\n";
        }
        @ob_flush();
        @flush();
    }
}

if (!function_exists('stream_message')) {
    function stream_message($msg) {
        $ts = date('Y-m-d H:i:s');
        $line = "[$ts] " . (string)$msg;
        if (php_sapi_name() === 'cli') {
            echo $line . PHP_EOL;
        } else {
            echo "<script>appendLine(" . json_encode($line) . ");</script>\n";
        }
        force_flush();
    }
}

if (!function_exists('log_activity')) {
    function log_activity($message) {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] " . (string)$message . "\n";
        @file_put_contents(__DIR__ . '/../crawler.log', $line, FILE_APPEND);
        if (php_sapi_name() === 'cli') {
            echo $line;
        }
        if (function_exists('stream_message')) {
            @stream_message((string)$message);
        }
        if (function_exists('force_flush')) {
            force_flush();
        }
    }
}

if (!function_exists('get_min_pages_threshold')) {
    function get_min_pages_threshold() {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = 0;
        $val = get_setting_value('min_pages_crawled');
        if ($val !== null) {
            $intVal = (int)$val;
            if ($intVal >= 0) $cached = $intVal;
        }
        return $cached;
    }
}

if (!function_exists('mark_domain_crawled_once')) {
    function mark_domain_crawled_once($domain_id) {
        static $marked = [];
        if (isset($marked[$domain_id])) return false;

        if (!ensure_db_connection()) return false;
        global $conn;
        $domain_id = (int)$domain_id;
        if ($domain_id <= 0) return false;

        $stmt = $conn->prepare("UPDATE `domains` SET `crawled` = 1, `date_crawled` = IFNULL(`date_crawled`, NOW()) WHERE `id` = ? AND `crawled` = 0 LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $domain_id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $marked[$domain_id] = true;
            log_activity("Domain {$domain_id} marked as crawled.");
            return true;
        }
        return false;
    }
}

if (!function_exists('update_domain_progress')) {
    function update_domain_progress($domain_id, $emails_found, $urls_crawled) {
        if (!ensure_db_connection()) return false;
        global $conn;
        $domain_id = (int)$domain_id;
        $emails_found = (int)$emails_found;
        $urls_crawled = (int)$urls_crawled;
        if ($domain_id <= 0) return false;

        $stmt = $conn->prepare("UPDATE `domains` SET `emails_found` = ?, `urls_crawled` = ? WHERE `id` = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('iii', $emails_found, $urls_crawled, $domain_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

/**
 * Check if a column exists in a table.
 */
if (!function_exists('column_exists')) {
    function column_exists($table, $column) {
        global $conn;
        $result = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$conn->real_escape_string($table)}' AND COLUMN_NAME = '{$conn->real_escape_string($column)}'");
        return $result && $result->num_rows > 0;
    }
}

/**
 * Check if a table exists.
 */
if (!function_exists('table_exists')) {
    function table_exists($table) {
        global $conn;
        $result = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$conn->real_escape_string($table)}'");
        return $result && $result->num_rows > 0;
    }
}
