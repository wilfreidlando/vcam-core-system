<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0075 §1 — the one call a project makes to send a customer to its portal.
 *
 * To keep an integration down to a single call, the customer and its plan
 * can ride along: `$customer` declares (or refreshes) the customer, and
 * `$planCode` subscribes it when it has no live contract yet. A project
 * that already manages both through the API simply omits them.
 */
final readonly class OpenPortalSessionCommand
{
    /**
     * @param array{display_name?: string|null, email?: string|null, phone?: string|null, locale?: string|null, metadata?: array<string, mixed>|null}|null $customer
     */
    public function __construct(
        public string $projectId,
        public string $customerRef,
        public string $flow = 'manage',
        public ?string $invoiceId = null,
        public ?string $returnUrl = null,
        public ?array $customer = null,
        public ?string $planCode = null,
        public ?int $trialDays = null,
        public ?string $callerIp = null,
        public ?string $correlationId = null,
    ) {}
}
