<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\SettlementLedger;

final class RecordingSettlementLedger implements SettlementLedger
{
    /** @var list<string> payment ids booked as a direct settlement */
    public array $direct = [];

    /** @var list<array{paymentId: string, amount: int, currency: string}> */
    public array $unallocated = [];

    public bool $supportsCurrency = true;

    /** @var list<array{exceptionId: string, amount: int, credit: string}> */
    public array $resolutions = [];

    public function recordDirectSettlement(Payment $payment): void
    {
        $this->direct[] = $payment->id;
    }

    public function recordUnallocatedSettlement(Payment $payment, int $amountMinor, string $currency): bool
    {
        if (! $this->supportsCurrency) {
            return false;
        }

        $this->unallocated[] = ['paymentId' => $payment->id, 'amount' => $amountMinor, 'currency' => $currency];

        return true;
    }

    public function recordExceptionResolution(MoneyException $exception): void
    {
        $credit = $exception->resolution?->creditAccount();
        if ($exception->movesMoney() && $credit !== null) {
            $this->resolutions[] = ['exceptionId' => $exception->id, 'amount' => $exception->bookedAmount, 'credit' => $credit];
        }
    }
}
