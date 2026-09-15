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
    ) {}

    public static function success(int $httpStatus): self
    {
        return new self(true, $httpStatus, null);
    }

    public static function failure(?int $httpStatus, string $error): self
    {
        return new self(false, $httpStatus, $error);
    }
}
