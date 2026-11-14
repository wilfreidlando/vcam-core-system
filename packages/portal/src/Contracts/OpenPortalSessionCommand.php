<?php

declare(strict_types=1);

namespace Core\Portal\Contracts;

/**
 * ADR-0075 §1 — the one call a project makes to send a customer to its portal.
 *
 * To keep an integration down to a single call, the customer and its plan
 * can ride along: `$customer` declares (or refreshes) the customer, and
 * `$planCode` subscribes it when it has no live contract yet. A project
 * that already manages both through the API simply omits them.
 *
 * The command lives in the module's **published surface**: the console
 * opens a customer's space exactly like an application's server does,
 * through the same contract (ADR-0125). The fields are the ones the API
 * has always taken — the caller's side does not change.
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
