<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Jobs;

use Core\Payment\Domain\Port\PaymentCallbackSender;
use Core\Payment\Domain\Port\PaymentRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * One delivery attempt to a Payment's `callbackUrl`. Unlike
 * `Core\Webhook\Presentation\Jobs\DeliverWebhookJob`, there is no
 * persisted delivery/DLQ row backing this — an intentionally smaller
 * scope than the Project-registered webhook plane (documented trade-off:
 * `GET /v1/payments/{id}` always reflects the true, current status
 * regardless of whether this callback ever lands, so a caller has a
 * reliable fallback even after this job exhausts its retries). Retry
 * timing is Laravel's own queue retry mechanism (`$tries`/`backoff()`),
 * not a hand-computed schedule.
 *
 * Looked up by `(providerCode, transactionRef)`, not a payment id,
 * because that's all `DeliverPaymentCallbackListener` has from the
 * outbox payload (`PaymentStatusChanged` carries no `payment_id` — it's
 * the canonical, cross-module event shape, ADR-0038 §4/§5).
 */
final class DeliverPaymentCallbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public function __construct(
        private readonly string $providerCode,
        private readonly string $transactionRef,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 900, 1800, 3600];
    }

    public function handle(PaymentRepository $payments, PaymentCallbackSender $sender): void
    {
        $payment = $payments->findByProviderTransactionRef($this->providerCode, $this->transactionRef);
        if ($payment === null || $payment->callbackUrl === null) {
            // No callback configured for this payment — the common case,
            // since most payments don't set one. Nothing to do.
            return;
        }

        $outcome = $sender->send($payment);
        if (! $outcome->success) {
            // Throwing (rather than returning) is what makes Laravel's
            // queue retry this job per $tries/backoff().
            throw new RuntimeException($outcome->error ?? 'Payment callback delivery failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LoggerInterface::class)->error('payment.callback.delivery_exhausted', [
            'provider_code' => $this->providerCode,
            'transaction_ref' => $this->transactionRef,
            'error' => $exception?->getMessage(),
        ]);
    }
}
