<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Moves value between wallets of one project, in a
 * single ledger transaction: every leg lands, or none does (ADR-0065).
 */
final readonly class TransferFundsCommand
{
    /**
     * @param list<TransferLeg> $legs
     * @param array<string, mixed> $metadata Opaque to the Core, echoed back in the history.
     */
    public function __construct(
        public string $projectId,
        public string $idempotencyKey,
        public string $currency,
        public array $legs,
        public ?string $description = null,
        public array $metadata = [],
    ) {}
}
