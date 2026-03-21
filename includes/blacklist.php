<?php
/**
 * Phase 2 — Domain Blacklist Management
 */

function is_domain_blacklisted(string $domain): bool {
    global $conn;
    $domain = strtolower(trim($domain));
    $stmt = $conn->prepare("SELECT blacklisted FROM domains WHERE domain = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $domain);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $stmt->close();
        return (int)$row['blacklisted'] === 1;
    }
    $stmt->close();
    return false;
}

function blacklist_domain(int $domain_id, string $reason): void {
    global $conn;
    $stmt = $conn->prepare("UPDATE domains SET blacklisted = 1, blacklist_reason = ? WHERE id = ?");
    if (!$stmt) return;
    $stmt->bind_param('si', $reason, $domain_id);
    $stmt->execute();
    $stmt->close();
    if (function_exists('log_activity')) {
        log_activity("Domain {$domain_id} blacklisted: {$reason}");
    }
}

function unblacklist_domain(int $domain_id): void {
    global $conn;
    $stmt = $conn->prepare("UPDATE domains SET blacklisted = 0, blacklist_reason = NULL WHERE id = ?");
    if (!$stmt) return;
    $stmt->bind_param('i', $domain_id);
    $stmt->execute();
    $stmt->close();
}

function get_blacklisted_domains(int $limit = 50, int $offset = 0): array {
    global $conn;
    $stmt = $conn->prepare("SELECT id, domain, blacklist_reason, date_crawled FROM domains WHERE blacklisted = 1 ORDER BY id DESC LIMIT ? OFFSET ?");
    if (!$stmt) return [];
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function count_blacklisted_domains(): int {
    global $conn;
    $result = $conn->query("SELECT COUNT(*) AS c FROM domains WHERE blacklisted = 1");
    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['c'];
    }
    return 0;
}
