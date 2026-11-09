<?php
declare(strict_types=1);

namespace Sankris\Stripe;

/** Minimal Stripe REST client over curl (no SDK needed, vendor stays as GYM One ships it). */
final class HttpGateway implements Gateway
{
    private const API = 'https://api.stripe.com/v1/';
    private const VERSION = '2024-06-20';

    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    public function createCustomer(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', 'customers', $params, $idempotencyKey);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', 'checkout/sessions', $params, $idempotencyKey);
    }

    public function createPaymentIntent(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', 'payment_intents', $params, $idempotencyKey);
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->request('GET', 'payment_intents/' . rawurlencode($id));
    }

    public function retrieveSetupIntent(string $id): array
    {
        return $this->request('GET', 'setup_intents/' . rawurlencode($id));
    }

    public function retrievePaymentMethod(string $id): array
    {
        return $this->request('GET', 'payment_methods/' . rawurlencode($id));
    }

    public function updateCustomer(string $id, array $params): array
    {
        return $this->request('POST', 'customers/' . rawurlencode($id), $params);
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', 'refunds', $params, $idempotencyKey);
    }

    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $ch = curl_init();
        $url = self::API . $path;
        $headers = ['Authorization: Bearer ' . $this->secretKey, 'Stripe-Version: ' . self::VERSION];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        if ($method === 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new StripeError('No se pudo conectar con Stripe: ' . $err);
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            throw new StripeError('Respuesta no válida de Stripe', $status);
        }
        if ($status >= 400) {
            throw new StripeError($body['error']['message'] ?? ('Error de Stripe ' . $status), $status, $body);
        }
        return $body;
    }
}
