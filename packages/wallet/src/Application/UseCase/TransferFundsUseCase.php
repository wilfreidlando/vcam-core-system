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
use Core\Wallet\Contracts\TransferFunds;
use Core\Wallet\Contracts\TransferFundsCommand;
use Core\Wallet\Contracts\WalletOperationView;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\InvalidWalletOperation;
use Core\Wallet\Domain\Model\SystemAccount;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Model\WalletOperation;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletOperationRepository;
use LogicException;

/**
 * ADR-0065 — moves value between wallets of one project and one
 * environment, as ONE ledger transaction: every leg lands, or none does.
 * Zero-sum by construction (each leg debits one wallet account and credits
 * another), so no value is ever created — the concern ADR-0033 §1 raised
 * against a public credit endpoint does not apply.
 *
 * Order of work, inside one database transaction:
 *
 * 1. replay check on the caller's key (cheap path, no lock);
 * 2. resolve sources (must exist) and destinations (created lazily);
 * 3. lock every balance involved in ascending wallet-id order — two
 *    transfers touching the same wallets in a different leg order would
 *    otherwise deadlock (ADR-0006);
 * 4. replay check again, now serialised: a concurrent twin may have
 *    committed between steps 1 and 3;
 * 5. check funds on the NET outflow of each wallet;
 * 6. post, apply balances, record the operation, append one event per
 *    wallet whose balance changed.
 */
final readonly class TransferFundsUseCase implements TransferFunds
{
    public const MAX_LEGS = 20;

    private const LEDGER_TYPE = 'wallet_transfer';

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

    public function transfer(TransferFundsCommand $command): WalletOperationView
    {
        $this->validate($command);

        return $this->unitOfWork->transactional(function () use ($command): WalletOperationView {
            $existing = $this->operations->findByIdempotencyKey($command->projectId, $command->environment, $command->idempotencyKey);
            if ($existing !== null) {
                return $this->views->ofReplay($existing);
            }

            // Keyed by the caller's party reference; `$touched` re-keys the
            // same wallets by id, keeping the reference for the answer.
            /** @var array<string, Wallet> $byRef */
            $byRef = [];
            /** @var array<string, array{wallet: Wallet, ref: string}> $touched */
            $touched = [];
            foreach ($command->legs as $leg) {
                $byRef[$leg->from] ??= $this->provisioner->source($command->projectId, $command->environment, $leg->from, $command->currency);
                $touched[$byRef[$leg->from]->id] = ['wallet' => $byRef[$leg->from], 'ref' => $leg->from];
            }
            foreach ($command->legs as $leg) {
                $byRef[$leg->to] ??= $this->provisioner->destination($command->projectId, $command->environment, $leg->to, $command->currency, $leg->toPartyType);
                $touched[$byRef[$leg->to]->id] = ['wallet' => $byRef[$leg->to], 'ref' => $leg->to];
            }
            $walletIds = array_keys($touched);
            sort($walletIds);

            /** @var array<string, WalletBalance> $locked */
            $locked = [];
            foreach ($walletIds as $walletId) {
                $locked[$walletId] = $this->balances->lockForUpdate($walletId);
            }

            $ledgerKey = self::ledgerKey($command->environment, $command->idempotencyKey);
            if ($this->ledgerPoster->hasBeenPosted($command->projectId, $ledgerKey)) {
                return $this->replayAfterRace($command);
            }

            /** @var array<string, int> $net */
            $net = [];
            foreach ($walletIds as $walletId) {
                $net[$walletId] = 0;
            }
            foreach ($command->legs as $leg) {
                $net[$byRef[$leg->from]->id] -= $leg->amountMinor;
                $net[$byRef[$leg->to]->id] += $leg->amountMinor;
            }
            foreach ($net as $walletId => $delta) {
                if ($delta < 0 && $locked[$walletId]->available->isLessThan(Money::ofMinor(-$delta, $command->currency))) {
                    throw InsufficientFunds::forParty($touched[$walletId]['ref'], $command->currency);
                }
            }

            $accounts = [];
            foreach ($walletIds as $walletId) {
                $accounts[$walletId] = $this->accountResolver->resolveOrCreate(new ResolveOrCreateAccountCommand(
                    $command->projectId,
                    SystemAccount::WALLET,
                    $walletId,
                    $command->currency,
                ))->id;
            }

            $entries = [];
            $legs = [];
            $total = 0;
            foreach ($command->legs as $leg) {
                $entries[] = new LedgerEntryInput($accounts[$byRef[$leg->from]->id], Direction::Debit, $leg->amountMinor, $command->currency);
                $entries[] = new LedgerEntryInput($accounts[$byRef[$leg->to]->id], Direction::Credit, $leg->amountMinor, $command->currency);
                $legs[] = ['from' => $leg->from, 'to' => $leg->to, 'amount_minor' => $leg->amountMinor];
                $total += $leg->amountMinor;
            }

            $operationId = $this->identifier->generate();
            $posted = $this->ledgerPoster->post(new PostLedgerTransactionCommand(
                $command->projectId,
                self::LEDGER_TYPE,
                $operationId,
                $ledgerKey,
                $entries,
            ));
            if ($posted->wasAlreadyPosted) {
                return $this->replayAfterRace($command);
            }

            $after = [];
            foreach ($locked as $walletId => $balance) {
                $delta = $net[$walletId];
                $available = $delta >= 0
                    ? $balance->available->plus(Money::ofMinor($delta, $command->currency))
                    : $balance->available->minus(Money::ofMinor(-$delta, $command->currency));
                $after[$walletId] = new WalletBalance($walletId, $available, $balance->held, $delta === 0 ? $balance->version : $balance->version + 1);
                if ($delta !== 0) {
                    $this->balances->save($after[$walletId]);
                }
            }

            $operation = new WalletOperation(
                $operationId,
                $command->projectId,
                $command->environment,
                WalletOperation::TRANSFER,
                $command->idempotencyKey,
                $command->currency,
                $total,
                $command->description,
                $command->metadata,
                $legs,
                null,
                $posted->id,
                $this->clock->now(),
            );
            $this->operations->save($operation);

            foreach ($net as $walletId => $delta) {
                if ($delta === 0) {
                    continue;
                }
                $this->outbox->append(new DomainEvent(
                    $this->identifier->generate(),
                    $command->projectId,
                    $delta > 0 ? 'wallet.credited' : 'wallet.debited',
                    array_filter([
                        'wallet_id' => $walletId,
                        'party_id' => $touched[$walletId]['wallet']->partyId,
                        'party_external_ref' => $touched[$walletId]['ref'],
                        'amount_minor' => abs($delta),
                        'currency' => $command->currency,
                        'operation_id' => $operationId,
                        'operation_type' => WalletOperation::TRANSFER,
                        'reference' => $command->idempotencyKey,
                        'description' => $command->description,
                    ], static fn (mixed $value): bool => $value !== null),
                    null,
                    $this->clock->now(),
                    environment: $command->environment,
                ));
            }

            return $this->views->of($operation, $touched, $after);
        });
    }

    public static function ledgerKey(string $environment, string $idempotencyKey): string
    {
        return 'wallet_transfer:'.$environment.':'.$idempotencyKey;
    }

    private function replayAfterRace(TransferFundsCommand $command): WalletOperationView
    {
        $winner = $this->operations->findByIdempotencyKey($command->projectId, $command->environment, $command->idempotencyKey)
            ?? throw new LogicException('The ledger holds this transfer but its operation record is missing.');

        return $this->views->ofReplay($winner);
    }

    private function validate(TransferFundsCommand $command): void
    {
        $count = count($command->legs);
        if ($count === 0 || $count > self::MAX_LEGS) {
            throw InvalidWalletOperation::because(sprintf('A transfer carries between 1 and %d legs, %d given.', self::MAX_LEGS, $count));
        }
        if (! $this->currencies->isSupported($command->currency)) {
            throw InvalidWalletOperation::because("The currency {$command->currency} is not supported.");
        }
        foreach ($command->legs as $index => $leg) {
            if ($leg->amountMinor <= 0) {
                throw InvalidWalletOperation::because("Leg {$index}: the amount must be a positive integer in minor units.");
            }
            if (trim($leg->from) === '' || trim($leg->to) === '') {
                throw InvalidWalletOperation::because("Leg {$index}: both parties must be named.");
            }
            if ($leg->from === $leg->to) {
                throw InvalidWalletOperation::because("Leg {$index}: a party cannot transfer to itself.");
            }
        }
    }
}
