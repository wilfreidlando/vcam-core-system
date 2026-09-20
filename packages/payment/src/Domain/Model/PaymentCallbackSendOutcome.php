<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * Result of one HTTP attempt to a Payment's `callbackUrl` — mirrors
 * `Core\Webhook\Domain\Model\WebhookSendOutcome`'s shape.
 */
final readonly class PaymentCallbackSendOutcome
{
    private function __construct(
        public bool $success,
        public ?int $httpStatus,
        public ?string $error,
    ) {}

    public static function success(int $httpStatus): self
    {
        return new self(true, $httpStatus, null);
    }

    public static function failure(?int $httpStatus, string $error): self
    {
        return new self(false, $httpStatus, $error);
    }

    public static function noCallbackConfigured(): self
    {
        return new self(true, null, null);
    }
}
