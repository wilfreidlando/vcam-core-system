<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

final readonly class AccountRef
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $reference,
        public string $currency,
    ) {}
}
