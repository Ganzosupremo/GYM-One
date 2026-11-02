<?php
declare(strict_types=1);

namespace Sankris\Stripe;

/** Verifies the Stripe-Signature header (HMAC-SHA256 of "timestamp.payload"). */
final class Webhook
{
    public static function verify(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        if ($secret === '') {
            throw new StripeError('Falta STRIPE_WEBHOOK_SECRET en .env');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new StripeError('Firma de webhook ausente o mal formada', 400);
        }
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            throw new StripeError('Firma de webhook vencida', 400);
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $event = json_decode($payload, true);
                if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
                    throw new StripeError('Evento no válido', 400);
                }
                return $event;
            }
        }
        throw new StripeError('Firma de webhook no válida', 400);
    }

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
