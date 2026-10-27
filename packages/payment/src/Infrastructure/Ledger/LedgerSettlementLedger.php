<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Ledger;

use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Payment\Domain\Model\ClearingReclassificationOutcome;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\SettlementAccount;
use Core\Payment\Domain\Port\SettlementLedger;
use Psr\Log\LoggerInterface;

/**
 * `SettlementLedger` over the ledger's `Contracts/` (G3c) — `payment` may
 * depend on `ledger`, never the reverse (G2).
 *
 * Idempotency key = the payment id, the same key `CreditWallet` already
 * uses for a wallet credit (ADR-0033 §2): exactly one settlement
 * transaction per payment, whichever path booked it. `external_ref` = the
 * payment id too, the join key reconciliation uses (ADR-0078 §3).
 *
 * ADR-0076 §2 — the DEBIT of a settlement lands on the provider's own
 * account (`clearing` with the provider's code as its reference), while the
 * CREDIT stays project-level (`revenue`, `payment_exceptions`,
 * `withdrawal`): the money the provider holds is the provider's, the
 * revenue is the project's.
 */
final readonly class LedgerSettlementLedger implements SettlementLedger
{
    public function __construct(
        private AccountResolver $accounts,
        private LedgerPoster $poster,
        private IsSupportedCurrency $currencies,
        private LoggerInterface $logger,
    ) {}

    public function recordDirectSettlement(Payment $payment): void
    {
        $this->book($payment, 'payment_settlement', SettlementAccount::REVENUE, $payment->amount, $payment->currency);
    }

    public function recordUnallocatedSettlement(Payment $payment, int $amountMinor, string $currency): bool
    {
        $currency = strtoupper($currency);

        if ($amountMinor <= 0 || ! $this->currencies->isSupported($currency)) {
            // Nothing bookable (unknown currency, or a non-positive amount the
            // ledger refuses by constraint). The exception stays on the
            // payment; an operator resolves it by hand.
            $this->logger->critical('payment.settlement.unbookable_exception', [
                'payment_id' => $payment->id,
                'project_id' => $payment->projectId,
                'confirmed_amount_minor' => $amountMinor,
                'confirmed_currency' => $currency,
            ]);

            return false;
        }

        $this->book($payment, 'payment_unallocated', SettlementAccount::PAYMENT_EXCEPTIONS, $amountMinor, $currency);

        return true;
    }

    public function recordExceptionResolution(MoneyException $exception): void
    {
        $creditKind = $exception->resolution?->creditAccount();
        if (! $exception->movesMoney() || $creditKind === null) {
            return;
        }

        $this->transfer(
            projectId: $exception->projectId,
            type: 'money_exception_resolution',
            externalRef: $exception->paymentId ?? $exception->id,
            idempotencyKey: 'money_exception:'.$exception->id.':resolution',
            debitKind: SettlementAccount::PAYMENT_EXCEPTIONS,
            debitReference: SettlementAccount::PROJECT_LEVEL,
            creditKind: $creditKind,
            creditReference: SettlementAccount::PROJECT_LEVEL,
            amountMinor: $exception->bookedAmount,
            currency: $exception->currency,
        );
    }

    public function recordClearingReclassification(
        string $projectId,
        string $providerCode,
        string $currency,
        int $amountMinor,
        string $reason,
    ): ClearingReclassificationOutcome {
        if ($amountMinor === 0) {
            return ClearingReclassificationOutcome::Unbookable;
        }

        $currency = strtoupper($currency);

        if (! $this->currencies->isSupported($currency)) {
            $this->logger->critical('payment.reclassification.unbookable_currency', [
                'project_id' => $projectId,
                'provider_code' => $providerCode,
                'currency' => $currency,
            ]);

            return ClearingReclassificationOutcome::Unbookable;
        }

        $reference = SettlementAccount::clearingReferenceFor($providerCode);

        // A positive net means the historical account holds money the
        // provider has: it moves to the provider's own account. A negative
        // net means the project is owed money: it moves back.
        $providerIsDebited = $amountMinor > 0;
        $moved = abs($amountMinor);

        $provider = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            SettlementAccount::CLEARING,
            $reference,
            $currency,
        ));

        $historical = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            SettlementAccount::CLEARING,
            SettlementAccount::PROJECT_LEVEL,
            $currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            $projectId,
            'clearing_reclassement',
            // The MOTIVE of the reclassification (ADR-0076 §2: « une
            // écriture de reclassement unique, motivée et auditée »).
            // The project it reclasses is already on the transaction
            // (`project_id`); the free field is for the reason.
            $reason,
            // One move per (project, currency, provider) — running the
            // command again finds this key already posted and moves
            // nothing.
            sprintf('clearing_reclassement:%s:%s:%s', $projectId, $currency, $reference),
            [
                new LedgerEntryInput(
                    $providerIsDebited ? $provider->id : $historical->id,
                    Direction::Debit,
                    $moved,
                    $currency,
                ),
                new LedgerEntryInput(
                    $providerIsDebited ? $historical->id : $provider->id,
                    Direction::Credit,
                    $moved,
                    $currency,
                ),
            ],
        ));

        return $posted->wasAlreadyPosted
            ? ClearingReclassificationOutcome::AlreadyPosted
            : ClearingReclassificationOutcome::Moved;
    }

    private function book(Payment $payment, string $type, string $creditKind, int $amountMinor, string $currency): void
    {
        $this->transfer(
            projectId: $payment->projectId,
            type: $type,
            externalRef: $payment->id,
            idempotencyKey: $payment->id,
            debitKind: SettlementAccount::CLEARING,
            debitReference: SettlementAccount::clearingReferenceFor($payment->providerCode),
            creditKind: $creditKind,
            creditReference: SettlementAccount::PROJECT_LEVEL,
            amountMinor: $amountMinor,
            currency: $currency,
        );
    }

    private function transfer(
        string $projectId,
        string $type,
        string $externalRef,
        string $idempotencyKey,
        string $debitKind,
        string $debitReference,
        string $creditKind,
        string $creditReference,
        int $amountMinor,
        string $currency,
    ): void {
        $debit = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            $debitKind,
            $debitReference,
            $currency,
        ));

        $credit = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            $creditKind,
            $creditReference,
            $currency,
        ));

        $this->poster->post(new PostLedgerTransactionCommand(
            $projectId,
            $type,
            $externalRef,
            $idempotencyKey,
            [
                new LedgerEntryInput($debit->id, Direction::Debit, $amountMinor, $currency),
                new LedgerEntryInput($credit->id, Direction::Credit, $amountMinor, $currency),
            ],
        ));
    }
}
