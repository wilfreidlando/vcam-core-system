<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Money\Money;
use Core\Support\UnitOfWork;
use Core\Wallet\Application\Service\OperationViews;
use Core\Wallet\Application\Service\WalletProvisioner;
use Core\Wallet\Contracts\RecordWithdrawal;
use Core\Wallet\Contracts\RecordWithdrawalCommand;
use Core\Wallet\Contracts\WalletOperationView;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\InvalidWalletOperation;
use Core\Wallet\Domain\Model\SystemAccount;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Model\WalletOperation;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletOperationRepository;
use LogicException;

/**
 * ADR-0065 — records that value left the Core's custody: a payout made
 * outside the Core (mobile money, bank wire), whose proof the caller keeps
 * in `externalReference`. Debits the wallet against the project's
 * `withdrawal` account — value leaves, none is created. Same lock-then-
 * replay-check discipline as `TransferFundsUseCase`.
 */
final readonly class RecordWithdrawalUseCase implements RecordWithdrawal
{
    private const LEDGER_TYPE = 'wallet_withdrawal';

    public function __construct(
        private UnitOfWork $unitOfWork,
        private WalletProvisioner $provisioner,
        private WalletBalanceRepository $balances,
        private WalletOperationRepository $operations,
        private OperationViews $views,
        private AccountResolver $accountResolver,
        private LedgerPoster $ledgerPoster,
        private IsSupportedCurrency $currencies,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function record(RecordWithdrawalCommand $command): WalletOperationView
    {
        if ($command->amountMinor <= 0) {
            throw InvalidWalletOperation::because('The amount must be a positive integer in minor units.');
        }
        if (trim($command->party) === '') {
            throw InvalidWalletOperation::because('The party must be named.');
        }
        if (! $this->currencies->isSupported($command->currency)) {
            throw InvalidWalletOperation::because("The currency {$command->currency} is not supported.");
        }

        return $this->unitOfWork->transactional(function () use ($command): WalletOperationView {
            $existing = $this->operations->findByIdempotencyKey($command->projectId, $command->idempotencyKey);
            if ($existing !== null) {
                return $this->views->ofReplay($existing);
            }

            $wallet = $this->provisioner->source($command->projectId, $command->party, $command->currency);
            $balance = $this->balances->lockForUpdate($wallet->id);

            $ledgerKey = 'wallet_withdrawal:'.$command->idempotencyKey;
            if ($this->ledgerPoster->hasBeenPosted($command->projectId, $ledgerKey)) {
                return $this->replayAfterRace($command);
            }

            $amount = Money::ofMinor($command->amountMinor, $command->currency);
            if ($balance->available->isLessThan($amount)) {
                throw InsufficientFunds::forParty($command->party, $command->currency);
            }

            $walletAccount = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                $command->projectId,
                SystemAccount::WALLET,
                $wallet->id,
                $command->currency,
            ));
            $withdrawalAccount = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                $command->projectId,
                SystemAccount::WITHDRAWAL,
                SystemAccount::PROJECT_LEVEL,
                $command->currency,
            ));

            $operationId = $this->identifier->generate();
            $posted = $this->ledgerPoster->post(new PostLedgerTransactionCommand(
                $command->projectId,
                self::LEDGER_TYPE,
                $operationId,
                $ledgerKey,
                [
                    new LedgerEntryInput($walletAccount->id, Direction::Debit, $command->amountMinor, $command->currency),
                    new LedgerEntryInput($withdrawalAccount->id, Direction::Credit, $command->amountMinor, $command->currency),
                ],
            ));
            if ($posted->wasAlreadyPosted) {
                return $this->replayAfterRace($command);
            }

            $after = new WalletBalance($wallet->id, $balance->available->minus($amount), $balance->held, $balance->version + 1);
            $this->balances->save($after);

            $operation = new WalletOperation(
                $operationId,
                $command->projectId,
                WalletOperation::WITHDRAWAL,
                $command->idempotencyKey,
                $command->currency,
                $command->amountMinor,
                $command->description,
                $command->metadata,
                [['from' => $command->party, 'to' => null, 'amount_minor' => $command->amountMinor]],
                $command->externalReference,
                $posted->id,
                $this->clock->now(),
            );
            $this->operations->save($operation);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'wallet.debited',
                array_filter([
                    'wallet_id' => $wallet->id,
                    'party_id' => $wallet->partyId,
                    'party_external_ref' => $command->party,
                    'amount_minor' => $command->amountMinor,
                    'currency' => $command->currency,
                    'operation_id' => $operationId,
                    'operation_type' => WalletOperation::WITHDRAWAL,
                    'reference' => $command->idempotencyKey,
                    'description' => $command->description,
                ], static fn (mixed $value): bool => $value !== null),
                null,
                $this->clock->now(),
            ));

            return $this->views->of($operation, [$wallet->id => ['wallet' => $wallet, 'ref' => $command->party]], [$wallet->id => $after]);
        });
    }

    private function replayAfterRace(RecordWithdrawalCommand $command): WalletOperationView
    {
        $winner = $this->operations->findByIdempotencyKey($command->projectId, $command->idempotencyKey)
            ?? throw new LogicException('The ledger holds this withdrawal but its operation record is missing.');

        return $this->views->ofReplay($winner);
    }
}
