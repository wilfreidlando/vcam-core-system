<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

/**
 * Result of one HTTP attempt to a Project's endpoint — the port-level
 * return type for `WebhookSender`, never the raw `ExternalResponse` (that
 * type belongs to `Core\Support\Integration`, and Domain stays pure).
 */
final readonly class WebhookSendOutcome
{
    private function __construct(
        public bool $success,
        public ?int $httpStatus,
        public ?string $error,
        // Null when no response was ever received (the request never
        // reached the endpoint — DNS/connect/timeout failure), present
        // otherwise, success or not — an operator diagnosing a dead
        // delivery needs to see exactly what the endpoint sent back, not
        // just that it failed.
        public ?string $responseBody = null,
    ) {}

    public static function success(int $httpStatus, ?string $responseBody): self
    {
        return new self(true, $httpStatus, null, $responseBody);
    }

    public static function failure(?int $httpStatus, string $error, ?string $responseBody = null): self
    {
        return new self(false, $httpStatus, $error, $responseBody);
    }
}
