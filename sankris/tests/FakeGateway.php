<?php
declare(strict_types=1);

namespace Sankris\Tests;

use Sankris\Stripe\Gateway;
use Sankris\Stripe\StripeError;

/** In-memory Stripe: honours idempotency keys like the real API. */
final class FakeGateway implements Gateway
{
    public array $calls = [];
    public array $byKey = [];
    public array $paymentIntents = [];
    /** Next PaymentIntent outcomes: 'succeeded' | 'declined' | 'requires_action' */
    public array $outcomes = [];
    private int $seq = 0;

    private function id(string $prefix): string
    {
        return $prefix . '_' . (++$this->seq);
    }

    private function once(string $key, callable $fn): array
    {
        $this->calls[] = $key;
        if (isset($this->byKey[$key])) {
            if ($this->byKey[$key] instanceof StripeError) {
                throw $this->byKey[$key];
            }
            return $this->byKey[$key];
        }
        try {
            return $this->byKey[$key] = $fn();
        } catch (StripeError $e) {
            $this->byKey[$key] = $e;
            throw $e;
        }
    }

    public function createCustomer(array $params, string $idempotencyKey): array
    {
        return $this->once($idempotencyKey, fn() => ['id' => $this->id('cus'), 'email' => $params['email'] ?? null]);
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->once($idempotencyKey, function () use ($params) {
            $id = $this->id('cs');
            return ['id' => $id, 'url' => 'https://checkout.stripe.test/' . $id, 'mode' => $params['mode']];
        });
    }

    public function createPaymentIntent(array $params, string $idempotencyKey): array
    {
        return $this->once($idempotencyKey, function () use ($params) {
            $outcome = array_shift($this->outcomes) ?? 'succeeded';
            $pi = [
                'id' => $this->id('pi'), 'amount' => $params['amount'], 'currency' => $params['currency'], 'customer' => $params['customer'],
                'metadata' => $params['metadata'], 'created' => time(), 'latest_charge' => $this->id('ch'),
            ];
            if ($outcome === 'declined') {
                $pi['status'] = 'requires_payment_method';
                $pi['amount_received'] = 0;
                $pi['last_payment_error'] = ['message' => 'Your card was declined.'];
                $this->paymentIntents[$pi['id']] = $pi;
                throw new StripeError('Your card was declined.', 402, ['error' => ['message' => 'Your card was declined.', 'payment_intent' => $pi]]);
            }
            $pi['status'] = $outcome;
            $pi['amount_received'] = $outcome === 'succeeded' ? $params['amount'] : 0;
            return $this->paymentIntents[$pi['id']] = $pi;
        });
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->paymentIntents[$id] ?? throw new StripeError('No such payment_intent', 404);
    }

    public function retrieveSetupIntent(string $id): array
    {
        return ['id' => $id, 'customer' => null, 'payment_method' => 'pm_' . $id];
    }

    public function retrievePaymentMethod(string $id): array
    {
        return ['id' => $id, 'card' => ['brand' => 'visa', 'last4' => '4242']];
    }

    public function updateCustomer(string $id, array $params): array
    {
        return ['id' => $id];
    }

    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->once($idempotencyKey, fn() => ['id' => $this->id('re'), 'amount' => $params['amount'], 'status' => 'succeeded']);
    }
}
