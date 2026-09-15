<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class LedgerTransactionRef
{
    /**
     * @param bool $wasAlreadyPosted True when `post()` found an existing
     *                               transaction for this idempotency key instead of creating one.
     *                               A caller that also updates its own materialised state (Wallet)
     *                               MUST check this and skip that update on true — otherwise a
     *                               replayed request applies its effect twice even though the ledger
     *                               itself correctly refused to.
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $externalRef,
        public bool $wasAlreadyPosted,
    ) {}
}
