<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0067 — the reconciliation candidate query against real PostgreSQL:
 * `GREATEST(updated_at, reconciled_at)` ordering and threshold, the 24-hour
 * age window, and `markReconciliationChecked()` leaving `updated_at` alone.
 */
final class PaymentReconciliationTurnTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-15T10:00:00Z';

    #[Test]
    public function stuck_payments_take_their_turn_by_last_look_and_old_ones_are_left_alone(): void
    {
        $repository = app(PaymentRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        $threshold = $now->modify('-2 minutes');
        $createdAfter = $now->modify('-24 hours');

        $oldest = $this->payment(PaymentStatus::Pending, createdMinutesAgo: 60, updatedMinutesAgo: 50);
        $middle = $this->payment(PaymentStatus::Processing, createdMinutesAgo: 40, updatedMinutesAgo: 30);
        $newest = $this->payment(PaymentStatus::Pending, createdMinutesAgo: 10, updatedMinutesAgo: 5);
        $fresh = $this->payment(PaymentStatus::Pending, createdMinutesAgo: 3, updatedMinutesAgo: 1); // too fresh for now
        $this->payment(PaymentStatus::Succeeded, createdMinutesAgo: 60, updatedMinutesAgo: 50);      // terminal
        $this->payment(PaymentStatus::Pending, createdMinutesAgo: 30 * 60, updatedMinutesAgo: 29 * 60); // older than 24 h

        self::assertSame([$oldest, $middle], $this->ids($repository->findStuckSince($threshold, 2, $createdAfter)));
        self::assertSame(3, $repository->countStuckSince($threshold, $createdAfter));

        // The two first ones are checked now: the third one comes first on the next run.
        $updatedAtBefore = DB::table('payments')->where('id', $oldest)->value('updated_at');
        $repository->markReconciliationChecked($oldest, $now);
        $repository->markReconciliationChecked($middle, $now);

        self::assertSame([$newest], $this->ids($repository->findStuckSince($threshold, 2, $createdAfter)));
        self::assertSame($updatedAtBefore, DB::table('payments')->where('id', $oldest)->value('updated_at'));

        // Three minutes later, every non-terminal payment of the window is a candidate,
        // least recently looked at first: the two checked ones come last.
        $later = $now->modify('+3 minutes');
        self::assertSame(
            [$newest, $fresh, $oldest, $middle],
            $this->ids($repository->findStuckSince($later->modify('-2 minutes'), 10, $later->modify('-24 hours'))),
        );
    }

    private function payment(PaymentStatus $status, int $createdMinutesAgo, int $updatedMinutesAgo): string
    {
        $id = (string) Str::uuid();
        app(PaymentRepository::class)->create(new Payment(
            id: $id,
            projectId: (string) Str::uuid(),
            channel: 'mtn_momo',
            providerCode: 'my-coolpay',
            idempotencyKey: (string) Str::uuid(),
            providerTransactionRef: (string) Str::uuid(),
            amount: 100,
            currency: 'XAF',
            status: $status,
            requiresAction: false,
            actionHint: null,
            paymentUrl: null,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
        ));
        $now = new DateTimeImmutable(self::NOW);
        DB::table('payments')->where('id', $id)->update([
            'created_at' => $now->modify("-{$createdMinutesAgo} minutes"),
            'updated_at' => $now->modify("-{$updatedMinutesAgo} minutes"),
        ]);

        return $id;
    }

    /**
     * @param list<Payment> $payments
     * @return list<string>
     */
    private function ids(array $payments): array
    {
        return array_map(static fn (Payment $payment): string => $payment->id, $payments);
    }
}
