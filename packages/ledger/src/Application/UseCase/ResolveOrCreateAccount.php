<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Contracts\AccountRef;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Ledger\Domain\Exception\AccountAlreadyExists;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAccount;
use Core\Ledger\Domain\Port\LedgerAccountRepository;
use Core\Support\Identifier;
use LogicException;

final readonly class ResolveOrCreateAccount implements AccountResolver
{
    public function __construct(
        private LedgerAccountRepository $accounts,
        private Identifier $identifier,
    ) {}

    public function resolveOrCreate(ResolveOrCreateAccountCommand $command): AccountRef
    {
        $kind = AccountKind::fromString($command->kind);

        $existing = $this->accounts->findByComposite($command->projectId, $kind, $command->reference, $command->currency);

        if ($existing !== null) {
            return $this->toRef($existing);
        }

        $account = new LedgerAccount(
            $this->identifier->generate(),
            $command->projectId,
            $kind,
            $command->reference,
            $command->currency,
        );

        try {
            $this->accounts->save($account);
        } catch (AccountAlreadyExists) {
            $winner = $this->accounts->findByComposite($command->projectId, $kind, $command->reference, $command->currency)
                ?? throw new LogicException('Account creation conflicted but no account was found afterwards.');

            return $this->toRef($winner);
        }

        return $this->toRef($account);
    }

    private function toRef(LedgerAccount $account): AccountRef
    {
        return new AccountRef($account->id, (string) $account->kind, $account->reference, $account->currency);
    }
}
