<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use DateTimeImmutable;

final readonly class WalletOperationView
{
    /**
     * @param list<array{from: string, to: string|null, amount_minor: int}> $legs
     * @param array<string, mixed> $metadata
     * @param list<PartyWalletView> $wallets The wallets this operation touched, with their balance now.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $reference,
        public string $currency,
        public int $totalMinor,
        public ?string $description,
        public array $metadata,
        public array $legs,
        public ?string $externalReference,
        public DateTimeImmutable $createdAt,
        public array $wallets,
    ) {}
}
