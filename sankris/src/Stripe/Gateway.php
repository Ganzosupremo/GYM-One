<?php
declare(strict_types=1);

namespace Sankris\Stripe;

/**
 * The Stripe calls the module needs. HttpGateway talks to the real API; tests use a fake.
 * Every call that creates something takes an idempotency key: Stripe returns the same
 * result for a repeated key, so a retry can never create a second charge.
 */
interface Gateway
{
    /** @return array Stripe Customer */
    public function createCustomer(array $params, string $idempotencyKey): array;

    /** @return array Stripe Checkout Session (id, url) */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /** @return array Stripe PaymentIntent (id, status, last_payment_error, latest_charge) */
    public function createPaymentIntent(array $params, string $idempotencyKey): array;

    public function retrievePaymentIntent(string $id): array;

    public function retrieveSetupIntent(string $id): array;

    public function retrievePaymentMethod(string $id): array;

    public function updateCustomer(string $id, array $params): array;

    /** @return array Stripe Refund */
    public function createRefund(array $params, string $idempotencyKey): array;
}
