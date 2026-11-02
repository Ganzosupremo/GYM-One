<?php
/**
 * Stripe webhook endpoint for the Sankris module.
 * Configure in Stripe: <public url>/stripe/webhook.php
 * Responds 200 when the event was processed (or already was), 400 for a bad signature
 * and 500 when processing failed, so Stripe retries later.
 */
declare(strict_types=1);

use Sankris\Stripe\Webhook;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
header('Content-Type: application/json');

try {
    /** @var \Sankris\App $app */
    $app = require __DIR__ . '/../sankris/bootstrap.php';
} catch (\Throwable $e) {
    error_log('[sankris webhook] boot: ' . $e->getMessage());
    http_response_code(500);
    echo '{"error":"unavailable"}';
    exit;
}

$secret = (string) ($app->env['STRIPE_WEBHOOK_SECRET'] ?? '');
if ($secret === '') {
    http_response_code(500);
    echo '{"error":"webhook secret not configured"}';
    exit;
}

$payload = (string) file_get_contents('php://input');
try {
    $event = Webhook::verify($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

try {
    $result = $app->stripe()->handleEvent($event);
    echo json_encode(['received' => true, 'result' => $result]);
} catch (\Throwable $e) {
    error_log('[sankris webhook] ' . ($event['id'] ?? '?') . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'processing failed']);
}
