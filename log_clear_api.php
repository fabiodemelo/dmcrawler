<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$logFile = __DIR__ . '/crawler.log';

// Empty log (create if missing)
$ok = (@file_put_contents($logFile, '') !== false);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to empty crawler.log (permissions?)']);
    exit;
}

echo json_encode([
    'ok' => true,
    'serverTime' => date('Y-m-d H:i:s'),
]);
