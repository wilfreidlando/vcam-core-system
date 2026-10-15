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
use Core\Wallet\Contracts\DebitWallet;
use Core\Wallet\Contracts\DebitWalletCommand;
use Core\Wallet\Contracts\WalletBalanceView;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Exception\WalletProjectMismatch;
use Core\Wallet\Domain\Model\SystemAccount;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;

/**
 * The mirror of `CreditWalletUseCase`: credits the project's clearing
 * account, debits the wallet's own account. Never creates a wallet lazily
 * — a debit against a wallet that was never credited is a bug in the
 * caller, not a case to paper over.
 */
final readonly class DebitWalletUseCase implements DebitWallet
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

    public function debit(DebitWalletCommand $command): WalletBalanceView
    {
        return $this->unitOfWork->transactional(function () use ($command): WalletBalanceView {
            $wallet = $this->wallets->find($command->walletId)
                ?? throw WalletNotFound::withId($command->walletId);

            if ($wallet->projectId !== $command->projectId) {
                throw WalletProjectMismatch::forWallet($wallet->id, $command->projectId);
            }

            // The lock is taken before checking funds, and before posting to
            // the ledger: two concurrent debits must serialise here, or both
            // could read "sufficient funds" against the same stale balance
            // and together overdraw it (ADR-0006).
            $balance = $this->balances->lockForUpdate($wallet->id);

            // Idempotency is checked BEFORE the funds check, not after: on a
            // replay the balance already reflects the original, successful
            // debit, so re-evaluating "sufficient funds" against the
            // already-reduced balance would wrongly reject a request that
            // already succeeded once.
            $isReplay = $this->ledgerPoster->hasBeenPosted($wallet->projectId, $command->idempotencyKey);

            if ($isReplay) {
                return $this->toView($balance);
            }

            $amount = Money::ofMinor($command->amountMinor, $wallet->currency);

            if ($balance->available->isLessThan($amount)) {
                throw InsufficientFunds::forWallet($wallet->id);
            }

            $walletAccount = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                $wallet->projectId,
                SystemAccount::WALLET,
                $wallet->id,
                $wallet->currency,
            ));

            $clearingAccount = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                $wallet->projectId,
                SystemAccount::CLEARING,
                SystemAccount::PROJECT_LEVEL,
                $wallet->currency,
            ));

            $posted = $this->ledgerPoster->post(new PostLedgerTransactionCommand(
                $wallet->projectId,
                'wallet_debit',
                $command->externalRef,
                $command->idempotencyKey,
                [
                    new LedgerEntryInput($walletAccount->id, Direction::Debit, $command->amountMinor, $wallet->currency),
                    new LedgerEntryInput($clearingAccount->id, Direction::Credit, $command->amountMinor, $wallet->currency),
                ],
            ));

            if ($posted->wasAlreadyPosted) {
                return $this->toView($balance);
            }

            $newBalance = new WalletBalance(
                $wallet->id,
                $balance->available->minus($amount),
                $balance->held,
                $balance->version + 1,
            );
            $this->balances->save($newBalance);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $wallet->projectId,
                'wallet.debited',
                [
                    'wallet_id' => $wallet->id,
                    'party_id' => $wallet->partyId,
                    'amount_minor' => $command->amountMinor,
                    'currency' => $wallet->currency,
                ],
                null,
                $this->clock->now(),
            ));

            return $this->toView($newBalance);
        });
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
