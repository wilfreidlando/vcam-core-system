<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Unit\Fakes;

use Core\Ledger\Contracts\AccountRef;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;

final class FakeAccountResolver implements AccountResolver
{
    /** @var array<string, AccountRef> */
    private array $accounts = [];

    private int $next = 1;

    public function resolveOrCreate(ResolveOrCreateAccountCommand $command): AccountRef
    {
        $key = "{$command->projectId}:{$command->kind}:{$command->reference}:{$command->currency}";

        return $this->accounts[$key] ??= new AccountRef(
            'account-'.$this->next++,
            $command->kind,
            $command->reference,
            $command->currency,
        );
    }
}
