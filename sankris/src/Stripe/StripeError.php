<?php
declare(strict_types=1);

namespace Sankris\Stripe;

final class StripeError extends \RuntimeException
{
    public ?array $body;
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0, ?array $body = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->body = $body;
    }

    /** Card declines come back as HTTP 402 with the PaymentIntent inside the error. */
    public function paymentIntent(): ?array
    {
        return $this->body['error']['payment_intent'] ?? null;
    }
}
