<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Money\Money;
use Core\Support\UnitOfWork;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Contracts\WalletBalanceView;
use Core\Wallet\Domain\Exception\WalletAlreadyExists;
use Core\Wallet\Domain\Exception\WalletProjectMismatch;
use Core\Wallet\Domain\Model\SystemAccount;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use LogicException;

/**
 * Credits a wallet, backed by a real double-entry posting (spec technique
 * §10): debit the project's clearing account, credit the wallet's own
 * ledger account, update the materialised balance under a row lock, append
 * the outbox event — all inside one database transaction (ADR-0006).
 *
 * There is no external source of funds yet (that arrives with Payment in
 * Lot 3): the clearing account is this operation's counterparty for now,
 * exactly as an administrative top-up or an ops correction would use it in
 * a live system too.
 */
final readonly class CreditWalletUseCase implements CreditWallet
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
        private AccountResolver $accountResolver,
        private LedgerPoster $ledgerPoster,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function credit(CreditWalletCommand $command): WalletBalanceView
    {
        return $this->unitOfWork->transactional(function () use ($command): WalletBalanceView {
            $wallet = $this->resolveOrCreateWallet($command->projectId, $command->partyId, $command->currency, $command->environment);

            $walletAccount = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                $command->projectId,
                SystemAccount::WALLET,
                $wallet->id,
                $command->currency,
            ));

            $clearingAccount = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                $command->projectId,
                SystemAccount::CLEARING,
                SystemAccount::reference($command->environment),
                $command->currency,
            ));

            $posted = $this->ledgerPoster->post(new PostLedgerTransactionCommand(
                $command->projectId,
                'wallet_credit',
                $command->externalRef,
                $command->idempotencyKey,
                [
                    new LedgerEntryInput($clearingAccount->id, Direction::Debit, $command->amountMinor, $command->currency),
                    new LedgerEntryInput($walletAccount->id, Direction::Credit, $command->amountMinor, $command->currency),
                ],
            ));

            $balance = $this->balances->lockForUpdate($wallet->id);

            if ($posted->wasAlreadyPosted) {
                // Replay: the ledger already reflects this operation. Applying
                // the credit again here would double it even though the
                // ledger itself correctly refused a second posting.
                return $this->toView($balance);
            }

            $newBalance = new WalletBalance(
                $wallet->id,
                $balance->available->plus(Money::ofMinor($command->amountMinor, $command->currency)),
                $balance->held,
                $balance->version + 1,
            );
            $this->balances->save($newBalance);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'wallet.credited',
                array_filter([
                    'wallet_id' => $wallet->id,
                    'party_id' => $command->partyId,
                    'party_external_ref' => $command->partyExternalRef,
                    'amount_minor' => $command->amountMinor,
                    'currency' => $command->currency,
                    'operation_type' => 'payment',
                    'payment_id' => $command->externalRef,
                ], static fn (mixed $value): bool => $value !== null),
                null,
                $this->clock->now(),
                environment: $command->environment,
            ));

            return $this->toView($newBalance);
        });
    }

    private function resolveOrCreateWallet(string $projectId, string $partyId, string $currency, string $environment): Wallet
    {
        $existing = $this->wallets->findByPartyAndCurrency($partyId, $currency);

        if ($existing !== null) {
            if (! $existing->belongsTo($projectId, $environment)) {
                throw WalletProjectMismatch::forWallet($existing->id, $projectId);
            }

            return $existing;
        }

        $wallet = new Wallet($this->identifier->generate(), $projectId, $partyId, $currency, 'active', $environment);

        try {
            $this->wallets->save($wallet);
        } catch (WalletAlreadyExists) {
            $winner = $this->wallets->findByPartyAndCurrency($partyId, $currency)
                ?? throw new LogicException('Wallet creation conflicted but no wallet was found afterwards.');

            return $winner;
        }

        $this->balances->initialize($wallet->id, $currency);

        return $wallet;
    }

    private function toView(WalletBalance $balance): WalletBalanceView
    {
        return new WalletBalanceView(
            $balance->walletId,
            $balance->available->amountMinor(),
            $balance->held->amountMinor(),
            $balance->available->currency(),
        );
    }
}
