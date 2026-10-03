<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Jobs;

use Core\Payment\Application\Service\PaymentJourney;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
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

    /**
     * @param array<string, mixed> $data the event's canonical `data` (ADR-0061)
     */
    public function __construct(
        private readonly string $paymentId,
        private readonly string $eventId,
        private readonly array $data,
    ) {
        // Audit 2026-10-03 §3.2 — its own queue, never behind webhooks.
        $this->onQueue('payment-callbacks');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 900, 1800, 3600];
    }

    public function handle(PaymentRepository $payments, PaymentCallbackSender $sender, PaymentJourney $journey): void
    {
        $payment = $payments->find($this->paymentId);
        if ($payment === null || $payment->callbackUrl === null) {
            // No callback configured for this payment — the common case,
            // since most payments don't set one. Nothing to do.
            return;
        }

        $outcome = $sender->send($payment, $this->eventId, $this->data);

        // ADR-0059 — every attempt is a step of the payment's "Parcours":
        // the exact signed request sent and what the integrator answered.
        $journey->record(
            $payment->id,
            $payment->projectId,
            PaymentTimelineEntryType::CallbackAttempt,
            sprintf(
                'Callback POST %s — tentative %d/%d : %s%s',
                $payment->callbackUrl,
                $this->attempts(),
                $this->tries,
                $outcome->success ? 'livré' : 'échec',
                $outcome->httpStatus === null ? ' (aucune réponse : '.($outcome->error ?? 'erreur réseau').')' : ' (HTTP '.$outcome->httpStatus.')',
            ),
            [
                'url' => $payment->callbackUrl,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'success' => $outcome->success,
                'request' => ['headers' => $outcome->requestHeaders, 'body' => $outcome->requestBody],
                'response' => ['status' => $outcome->httpStatus, 'body' => $outcome->responseBody],
                'duration_ms' => $outcome->durationMs,
                'error' => $outcome->error,
                'next' => match (true) {
                    $outcome->success => null,
                    $outcome->isPermanentFailure => 'abandon : erreur 4xx définitive',
                    $this->attempts() >= $this->tries => 'abandon : tentatives épuisées',
                    default => 'nouvel essai programmé',
                },
            ],
        );

        if ($outcome->success) {
            return;
        }

        // ADR-0055: a 4xx other than 408/429 is a caller-side defect
        // (typically a `callback_url` that doesn't accept POST — 405,
        // or a permanent auth/route failure). No amount of retry will
        // fix it; burn one Horizon turn and surface it via `failed()`
        // immediately. Transient failures (5xx, 408, 429, network)
        // still go through `$tries`/`backoff()`.
        if ($outcome->isPermanentFailure) {
            $this->fail($outcome->error ?? 'Payment callback delivery failed (permanent 4xx).');

            return;
        }

        throw new RuntimeException($outcome->error ?? 'Payment callback delivery failed.');
    }

    public function failed(?Throwable $exception): void
    {
        app(LoggerInterface::class)->error('payment.callback.delivery_exhausted', [
            'payment_id' => $this->paymentId,
            'event_id' => $this->eventId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
