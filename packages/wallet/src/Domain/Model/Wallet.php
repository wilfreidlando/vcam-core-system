<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Model;

/**
 * A value account attached to a Party, backed by the ledger (spec
 * fonctionnelle §5.3). One wallet per (party, currency) — a party wanting
 * to hold two currencies gets two wallets.
 */
final readonly class Wallet
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $partyId,
        public string $currency,
        public string $status,
        // ADR-0065 — `live` or `sandbox`, the environment of its party.
        public string $environment = 'live',
    ) {}

    public function belongsTo(string $projectId, string $environment): bool
    {
        return $this->projectId === $projectId && $this->environment === $environment;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
