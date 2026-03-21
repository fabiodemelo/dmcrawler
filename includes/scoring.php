<?php
/**
 * Phase 2 — Scoring Engine
 * Page quality, email confidence, fake detection, domain quality.
 */

/**
 * Score a page's quality from 0–100.
 * @return array ['score' => int, 'signals' => array]
 */
function score_page_quality(string $html, string $url, string $domain): array {
    $score = 50; // base
    $signals = [];
    $htmlLen = strlen($html);
    if ($htmlLen < 100) {
        return ['score' => 0, 'signals' => ['empty_page']];
    }

    // Strip tags for text analysis
    $text = strip_tags($html);
    $textLen = strlen(trim($text));
    $textRatio = $htmlLen > 0 ? $textLen / $htmlLen : 0;

    // ── Positive signals ──

    // Phone number present
    if (preg_match('/\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/', $text)) {
        $score += 10;
        $signals[] = 'phone_found';
    }

    // Address pattern (number + street-like words)
    if (preg_match('/\d{1,5}\s+\w+\s+(st|street|ave|avenue|blvd|boulevard|rd|road|dr|drive|ln|lane|way|ct|court|pl|place)\b/i', $text)) {
        $score += 8;
        $signals[] = 'address_found';
    }

    // Contact/about/team in URL path
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('/(contact|about|team|staff|people|leadership)/i', $path)) {
        $score += 12;
        $signals[] = 'contact_page';
    }

    // Good text-to-HTML ratio (business site content)
    if ($textRatio > 0.25) {
        $score += 8;
        $signals[] = 'good_text_ratio';
    } elseif ($textRatio > 0.15) {
        $score += 4;
        $signals[] = 'okay_text_ratio';
    }

    // Real social links
    if (preg_match('/href=["\'][^"\']*(?:linkedin\.com|facebook\.com|twitter\.com|x\.com)/i', $html)) {
        $score += 5;
        $signals[] = 'social_links';
    }

    // Business name in title
    if (preg_match('/<title[^>]*>(.+?)<\/title>/is', $html, $m)) {
        $title = strip_tags($m[1]);
        if (strlen($title) > 5 && strlen($title) < 200) {
            $score += 3;
            $signals[] = 'has_title';
        }
    }

    // Has meta description
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.{20,}?)["\']/i', $html)) {
        $score += 3;
        $signals[] = 'has_meta_desc';
    }

    // ── Negative signals ──

    // Too many emails on page (spam indicator)
    preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $text, $emailMatches);
    $emailCount = count(array_unique($emailMatches[0] ?? []));
    if ($emailCount > 20) {
        $score -= 25;
        $signals[] = 'too_many_emails_' . $emailCount;
    } elseif ($emailCount > 10) {
        $score -= 15;
        $signals[] = 'many_emails_' . $emailCount;
    }

    // Noindex meta
    if (preg_match('/<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex/i', $html)) {
        $score -= 10;
        $signals[] = 'noindex';
    }

    // Low text-to-HTML ratio (heavy template/ads)
    if ($textRatio < 0.05) {
        $score -= 15;
        $signals[] = 'very_low_text_ratio';
    } elseif ($textRatio < 0.10) {
        $score -= 8;
        $signals[] = 'low_text_ratio';
    }

    // Spam/junk indicators in content
    if (preg_match('/(viagra|cialis|casino|lottery|click here to unsubscribe|buy now cheap)/i', $text)) {
        $score -= 30;
        $signals[] = 'spam_content';
    }

    // Parked domain indicators
    if (preg_match('/(this domain|is for sale|buy this domain|domain parking|parked free|sedo\.com|godaddy\.com\/parking)/i', $text)) {
        $score -= 40;
        $signals[] = 'parked_domain';
    }

    // Hidden content (display:none with lots of text inside)
    if (preg_match_all('/display\s*:\s*none/i', $html) > 3) {
        $score -= 10;
        $signals[] = 'hidden_content';
    }

    // Very short page content
    if ($textLen < 200) {
        $score -= 10;
        $signals[] = 'very_short_content';
    }

    $score = max(0, min(100, $score));
    return ['score' => $score, 'signals' => $signals];
}

/**
 * Score an email's confidence from 0–100.
 * @return array ['score' => int, 'tier' => string, 'reasons' => array]
 */
function score_email_confidence(string $email, string $pageHtml, string $domain, int $pageQualityScore): array {
    $score = 50; // base
    $reasons = [];

    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return ['score' => 0, 'tier' => 'rejected', 'reasons' => ['invalid_format']];
    }
    $username = strtolower($parts[0]);
    $emailDomain = strtolower($parts[1]);

    // ── Username quality ──

    // Named patterns (firstname, firstname.lastname, etc.)
    if (preg_match('/^[a-z]+\.[a-z]+$/', $username)) {
        $score += 20;
        $reasons[] = 'named_pattern';
    } elseif (preg_match('/^[a-z]{2,}[._][a-z]{2,}$/', $username)) {
        $score += 15;
        $reasons[] = 'structured_name';
    }

    // Known role-based addresses (moderate value)
    $roleAccounts = ['info', 'sales', 'support', 'contact', 'hello', 'team', 'admin', 'office', 'hr', 'jobs', 'careers', 'marketing', 'media', 'press', 'billing', 'accounting'];
    if (in_array($username, $roleAccounts)) {
        $score += 10;
        $reasons[] = 'role_account';
    }

    // Random alphanumeric (negative)
    if (preg_match('/^[a-z0-9]{8,}$/', $username)) {
        // Check vowel ratio — real names have vowels
        $vowels = preg_match_all('/[aeiou]/', $username);
        $vowelRatio = strlen($username) > 0 ? $vowels / strlen($username) : 0;
        if ($vowelRatio < 0.2) {
            $score -= 30;
            $reasons[] = 'random_alphanumeric';
        }
    }

    // Excessive consecutive digits
    if (preg_match('/\d{5,}/', $username)) {
        $score -= 20;
        $reasons[] = 'excessive_digits';
    } elseif (preg_match('/\d{3,4}/', $username)) {
        $score -= 8;
        $reasons[] = 'some_digits';
    }

    // Very short username
    if (strlen($username) < 3) {
        $score -= 10;
        $reasons[] = 'short_username';
    }

    // ── Domain quality ──

    // Email domain matches crawled domain
    $crawlDomainClean = preg_replace('/^www\./', '', strtolower($domain));
    if ($emailDomain === $crawlDomainClean || str_ends_with($emailDomain, '.' . $crawlDomainClean)) {
        $score += 15;
        $reasons[] = 'domain_match';
    }

    // Check disposable domain
    if (is_disposable_domain($emailDomain)) {
        $score -= 40;
        $reasons[] = 'disposable_domain';
    }

    // Free email providers (not necessarily bad but less valuable)
    $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'mail.com', 'protonmail.com', 'yandex.com', 'live.com', 'msn.com'];
    if (in_array($emailDomain, $freeProviders)) {
        $score -= 5;
        $reasons[] = 'free_provider';
    }

    // ── Context quality ──

    // Email found near contact-related text
    $text = strip_tags($pageHtml);
    $emailPos = stripos($text, $email);
    if ($emailPos !== false) {
        $surrounding = substr($text, max(0, $emailPos - 200), 400);
        if (preg_match('/(contact|reach|email|call|get in touch|write to|send|inquiry|question)/i', $surrounding)) {
            $score += 10;
            $reasons[] = 'contact_context';
        }
    }

    // Page quality contribution (proportional)
    if ($pageQualityScore >= 70) {
        $score += 10;
        $reasons[] = 'high_quality_page';
    } elseif ($pageQualityScore >= 50) {
        $score += 5;
        $reasons[] = 'decent_page';
    } elseif ($pageQualityScore < 30) {
        $score -= 10;
        $reasons[] = 'low_quality_page';
    }

    $score = max(0, min(100, $score));

    // Determine tier
    if ($score >= 80) {
        $tier = 'high';
    } elseif ($score >= 60) {
        $tier = 'acceptable';
    } elseif ($score >= 40) {
        $tier = 'low';
    } else {
        $tier = 'rejected';
    }

    return ['score' => $score, 'tier' => $tier, 'reasons' => $reasons];
}

/**
 * Detect if an email is likely fake or should be rejected.
 * @return array ['is_fake' => bool, 'reason' => string|null, 'category' => string|null]
 */
function detect_fake_email(string $email): array {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return ['is_fake' => true, 'reason' => 'Invalid email format', 'category' => 'syntax'];
    }
    $username = strtolower($parts[0]);
    $emailDomain = strtolower($parts[1]);

    // Check format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['is_fake' => true, 'reason' => 'Invalid email syntax', 'category' => 'syntax'];
    }

    // Check bad file extensions
    $badExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'mp4', 'avi', 'mov', 'doc', 'docx', 'xls', 'xlsx', 'css', 'js'];
    $tld = pathinfo($emailDomain, PATHINFO_EXTENSION);
    // Check if the whole email looks like a filename
    foreach ($badExtensions as $ext) {
        if (preg_match('/\.' . preg_quote($ext, '/') . '$/i', $email)) {
            return ['is_fake' => true, 'reason' => "Looks like a filename (.{$ext})", 'category' => 'bad_extension'];
        }
    }

    // Check too long
    if (strlen($email) > 60) {
        return ['is_fake' => true, 'reason' => 'Email too long (' . strlen($email) . ' chars)', 'category' => 'syntax'];
    }

    // Check trap/noreply addresses
    $trapPrefixes = ['noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply', 'do_not_reply', 'mailer-daemon', 'postmaster', 'root', 'test', 'example', 'bounce', 'abuse', 'spam', 'nobody'];
    if (in_array($username, $trapPrefixes)) {
        return ['is_fake' => true, 'reason' => "Trap/system address: {$username}@", 'category' => 'trap_name'];
    }

    // Known acceptable role accounts — skip further fake checks for these
    $allowedRoles = ['info', 'sales', 'support', 'contact', 'hello', 'team', 'office', 'hr', 'jobs', 'careers', 'marketing', 'media', 'press', 'billing', 'accounting', 'inquiries', 'general'];
    if (in_array($username, $allowedRoles)) {
        return ['is_fake' => false, 'reason' => null, 'category' => null];
    }

    // Check disposable domain
    if (is_disposable_domain($emailDomain)) {
        return ['is_fake' => true, 'reason' => "Disposable domain: {$emailDomain}", 'category' => 'disposable'];
    }

    // Check random alphanumeric pattern
    if (preg_match('/^[a-z0-9]{8,}$/', $username)) {
        $vowels = preg_match_all('/[aeiou]/', $username);
        $vowelRatio = strlen($username) > 0 ? $vowels / strlen($username) : 0;
        if ($vowelRatio < 0.15) {
            return ['is_fake' => true, 'reason' => 'Random alphanumeric username', 'category' => 'random_pattern'];
        }
    }

    // Check excessive consecutive digits
    if (preg_match('/\d{6,}/', $username)) {
        return ['is_fake' => true, 'reason' => 'Excessive digits in username', 'category' => 'random_pattern'];
    }

    // Check repeated characters
    if (preg_match('/(.)\1{4,}/', $username)) {
        return ['is_fake' => true, 'reason' => 'Repeated characters in username', 'category' => 'random_pattern'];
    }

    return ['is_fake' => false, 'reason' => null, 'category' => null];
}

/**
 * Check if a domain is in the disposable domains list.
 */
function is_disposable_domain(string $domain): bool {
    static $cache = [];
    $domain = strtolower(trim($domain));
    if (isset($cache[$domain])) {
        return $cache[$domain];
    }

    // Check database table
    global $conn;
    if ($conn instanceof mysqli && @$conn->ping()) {
        $stmt = $conn->prepare("SELECT 1 FROM disposable_domains WHERE domain = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $domain);
            $stmt->execute();
            $result = $stmt->get_result();
            $found = $result && $result->num_rows > 0;
            $stmt->close();
            $cache[$domain] = $found;
            return $found;
        }
    }

    // Fallback: hardcoded short list if DB unavailable
    $fallback = ['mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email', 'yopmail.com', 'sharklasers.com', 'temp-mail.org', 'fakeinbox.com', 'trashmail.com', 'discard.email'];
    $cache[$domain] = in_array($domain, $fallback);
    return $cache[$domain];
}

/**
 * Score a domain's quality from 0–100 and determine crawl budget.
 * @return array ['score' => int, 'tier' => string, 'budget' => int, 'signals' => array]
 */
function score_domain_quality(string $domain, string $homepageHtml): array {
    $score = 50;
    $signals = [];
    $htmlLen = strlen($homepageHtml);

    if ($htmlLen < 100) {
        return ['score' => 0, 'tier' => 'spam', 'budget' => 0, 'signals' => ['empty_or_down']];
    }

    $text = strip_tags($homepageHtml);
    $textLen = strlen(trim($text));

    // ── Positive ──

    // Has substantial content
    if ($textLen > 2000) {
        $score += 10;
        $signals[] = 'substantial_content';
    } elseif ($textLen > 500) {
        $score += 5;
        $signals[] = 'has_content';
    }

    // Has contact page link
    if (preg_match('/href=["\'][^"\']*contact/i', $homepageHtml)) {
        $score += 8;
        $signals[] = 'has_contact_link';
    }

    // Has about page link
    if (preg_match('/href=["\'][^"\']*about/i', $homepageHtml)) {
        $score += 5;
        $signals[] = 'has_about_link';
    }

    // Phone number on homepage
    if (preg_match('/\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/', $text)) {
        $score += 8;
        $signals[] = 'phone_on_homepage';
    }

    // Has real title
    if (preg_match('/<title[^>]*>(.{5,200}?)<\/title>/is', $homepageHtml)) {
        $score += 3;
        $signals[] = 'has_title';
    }

    // Has favicon
    if (preg_match('/rel=["\'](?:icon|shortcut icon)["\']/i', $homepageHtml)) {
        $score += 2;
        $signals[] = 'has_favicon';
    }

    // Has SSL (domain starts with https assumption — caller should pass fetched URL)
    // This is checked by the crawler when it fetches the page

    // ── Negative ──

    // Parked domain
    if (preg_match('/(this domain|is for sale|buy this domain|domain parking|parked free|coming soon|under construction)/i', $text)) {
        $score -= 35;
        $signals[] = 'parked_or_placeholder';
    }

    // Directory/aggregator
    if (preg_match('/(business directory|yellow pages|find businesses|list of companies)/i', $text)) {
        $score -= 20;
        $signals[] = 'directory_site';
    }

    // Very low text ratio
    $textRatio = $htmlLen > 0 ? $textLen / $htmlLen : 0;
    if ($textRatio < 0.05) {
        $score -= 15;
        $signals[] = 'very_low_text_ratio';
    }

    // Excessive redirects or empty body
    if ($textLen < 100) {
        $score -= 20;
        $signals[] = 'minimal_content';
    }

    // Spam keywords
    if (preg_match('/(viagra|cialis|casino|poker|slot machine|buy cheap|discount pharmacy)/i', $text)) {
        $score -= 40;
        $signals[] = 'spam_keywords';
    }

    $score = max(0, min(100, $score));

    // Determine tier and budget
    if ($score >= 70) {
        $tier = 'high';
        $budget = 20;
    } elseif ($score >= 45) {
        $tier = 'medium';
        $budget = 5;
    } elseif ($score >= 20) {
        $tier = 'low';
        $budget = 2;
    } else {
        $tier = 'spam';
        $budget = 0;
    }

    return ['score' => $score, 'tier' => $tier, 'budget' => $budget, 'signals' => $signals];
}

/**
 * Detect auto-generated/catalog email patterns.
 * e.g., sales.keg@korloy.com, sales.kip@korloy.com — product codes, not people.
 *
 * Legitimate: john.smith@domain.com (person), sales@domain.com (role account)
 * Fake pattern: sales.xyz@domain.com, info.abc@domain.com (role + short code)
 *
 * @return array ['is_pattern' => bool, 'reason' => string|null, 'pattern_key' => string|null]
 */
function detect_catalog_email(string $email): array {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return ['is_pattern' => false, 'reason' => null, 'pattern_key' => null];

    $username = strtolower($parts[0]);
    $emailDomain = strtolower($parts[1]);

    // Role prefixes that, when combined with a short suffix, indicate catalog emails
    $rolePrefixes = ['sales', 'info', 'support', 'contact', 'service', 'admin', 'office',
                     'marketing', 'billing', 'order', 'orders', 'enquiry', 'inquiry',
                     'export', 'import', 'purchase', 'account', 'accounts', 'dept', 'cs'];

    // Check for role.shortcode pattern (e.g., sales.keg, info.mx, support.na)
    if (preg_match('/^([a-z]+)[._]([a-z]{1,4})$/i', $username, $m)) {
        $prefix = strtolower($m[1]);
        $suffix = strtolower($m[2]);

        if (in_array($prefix, $rolePrefixes)) {
            // It's role.shortcode — this is a catalog/product code email
            // Exception: common real name combos like "sales.manager" or longer suffixes
            $realSuffixes = ['team', 'dept', 'admin', 'main', 'head', 'lead', 'mgr', 'dir'];
            if (!in_array($suffix, $realSuffixes)) {
                return [
                    'is_pattern' => true,
                    'reason' => "Catalog pattern: {$prefix}.{$suffix}@ (role + product/region code)",
                    'pattern_key' => $prefix . '@' . $emailDomain,
                ];
            }
        }
    }

    // Check for prefix + digits pattern (e.g., sales1@, sales02@, info3@)
    if (preg_match('/^([a-z]+)(\d{1,3})$/', $username, $m)) {
        $prefix = strtolower($m[1]);
        if (in_array($prefix, $rolePrefixes)) {
            return [
                'is_pattern' => true,
                'reason' => "Numbered role account: {$username}@",
                'pattern_key' => $prefix . '@' . $emailDomain,
            ];
        }
    }

    return ['is_pattern' => false, 'reason' => null, 'pattern_key' => null];
}

/**
 * Track email patterns per domain during a crawl run.
 * Detects when too many emails follow the same pattern from one domain.
 * Returns true if the email should be rejected based on pattern flooding.
 */
function is_pattern_flooding(string $email, array &$patternTracker, int $maxPerPattern = 2): bool {
    $result = detect_catalog_email($email);
    if (!$result['is_pattern']) return false;

    $key = $result['pattern_key'];
    if (!isset($patternTracker[$key])) {
        $patternTracker[$key] = 0;
    }
    $patternTracker[$key]++;

    // Allow first N of a pattern (e.g., sales@domain is fine), reject the rest
    return $patternTracker[$key] > $maxPerPattern;
}

/**
 * Check allowed TLDs for email domains.
 */
function is_allowed_email_tld(string $email): bool {
    $allowed = ['com', 'net', 'org', 'co', 'io', 'us', 'gov', 'ca', 'edu', 'mil', 'ai', 'dev', 'app', 'me', 'biz', 'tech', 'info', 'cc', 'tv', 'pro', 'uk', 'de', 'fr', 'es', 'it', 'nl', 'au', 'nz', 'in', 'br', 'mx', 'jp', 'kr', 'sg', 'hk', 'za'];
    $parts = explode('.', strtolower($email));
    $tld = end($parts);
    return in_array($tld, $allowed);
}
