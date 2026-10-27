<?php
/**
 * Disposable FlatRate → Centrifugo publish edge substitute.
 *
 * Accepts the same request shape as production Cloudflare /api/publish
 * (X-FlatRate-Realtime-Key + X-API-Key + JSON body with channel/data/idempotency_key)
 * and forwards channel+data to a localhost Centrifugo HTTP API.
 *
 * PROVEN path: CentrifugoRealtimePublisher → this edge → Centrifugo /api/publish
 * NOT PROVEN: Cloudflare edge at realtime.flatrate.wiki
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

$expectedEdge = getenv('EDGE_KEY') ?: '';
$expectedApi = getenv('API_KEY') ?: '';
$centrifugoPublish = getenv('CENTRIFUGO_PUBLISH_URL') ?: 'http://127.0.0.1:8000/api/publish';
$logPath = getenv('EDGE_LOG') ?: '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && ($path === '/healthz' || $path === '/health')) {
    echo json_encode(['ok' => true, 'edge' => 'local-test-equivalent']);
    exit;
}

if ($method !== 'POST' || $path !== '/api/publish') {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 404, 'message' => 'not found']]);
    exit;
}

$edgeKey = $_SERVER['HTTP_X_FLATRATE_REALTIME_KEY'] ?? '';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($expectedEdge === '' || $expectedApi === '' || !hash_equals($expectedEdge, $edgeKey) || !hash_equals($expectedApi, $apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => ['code' => 401, 'message' => 'unauthorized']]);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw === false ? '' : $raw, true);
if (!is_array($body) || !isset($body['channel']) || !array_key_exists('data', $body)) {
    http_response_code(400);
    echo json_encode(['error' => ['code' => 400, 'message' => 'invalid body']]);
    exit;
}

$channel = (string) $body['channel'];
if ($channel === '' || !str_starts_with($channel, '$flatrate-live-')) {
    http_response_code(400);
    echo json_encode(['error' => ['code' => 400, 'message' => 'invalid channel']]);
    exit;
}

$forward = [
    'channel' => $channel,
    'data' => $body['data'],
];
$payload = json_encode($forward, JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    http_response_code(500);
    echo json_encode(['error' => ['code' => 500, 'message' => 'encode failed']]);
    exit;
}

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: apikey {$expectedApi}\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 3,
    ],
]);

$resp = @file_get_contents($centrifugoPublish, false, $ctx);
$status = 502;
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $status = (int) $m[1];
}

if ($logPath !== '') {
    $line = json_encode([
        'ts' => gmdate('c'),
        'channel' => $channel,
        'idempotency_key' => $body['idempotency_key'] ?? null,
        'centrifugo_status' => $status,
        'publish_path' => 'local_edge_to_centrifugo_http_api',
    ], JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

http_response_code($status > 0 ? $status : 502);
echo $resp === false ? json_encode(['error' => ['code' => 502, 'message' => 'centrifugo unreachable']]) : $resp;
