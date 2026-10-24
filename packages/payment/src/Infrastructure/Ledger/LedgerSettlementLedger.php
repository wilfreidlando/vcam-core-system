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
            creditKind: $creditKind,
            amountMinor: $exception->bookedAmount,
            currency: $exception->currency,
        );
    }

    private function book(Payment $payment, string $type, string $creditKind, int $amountMinor, string $currency): void
    {
        $this->transfer(
            projectId: $payment->projectId,
            type: $type,
            externalRef: $payment->id,
            idempotencyKey: $payment->id,
            debitKind: SettlementAccount::CLEARING,
            creditKind: $creditKind,
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
        string $creditKind,
        int $amountMinor,
        string $currency,
    ): void {
        $debit = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            $debitKind,
            SettlementAccount::PROJECT_LEVEL,
            $currency,
        ));

        $credit = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $projectId,
            $creditKind,
            SettlementAccount::PROJECT_LEVEL,
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
