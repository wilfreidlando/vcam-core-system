<?php

declare(strict_types=1);

namespace Core\Payment\Application\Service;

use Core\Payment\Domain\Model\PaymentTimelineEntry;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\Domain\Port\PaymentTimeline;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\Observability\ExchangeJournal;
use Core\Support\Observability\IntegrationExchange;
use DateTimeImmutable;

/**
 * ADR-0059 — writes the steps of a payment's "Parcours". Used by the use
 * cases (initiation, status change, reconciliation) and by the HTTP and
 * queue entry points (API request/response, provider callback, callback
 * attempts). Everything handed to it must already be redacted; provider
 * exchanges come pre-redacted from `ExchangeJournal`.
 */
final readonly class PaymentJourney
{
    public function __construct(
        private PaymentTimeline $timeline,
        private Identifier $identifier,
        private Clock $clock,
        private ExchangeJournal $exchanges,
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public function record(
        string $paymentId,
        string $projectId,
        PaymentTimelineEntryType $type,
        string $summary,
        array $details = [],
        ?DateTimeImmutable $occurredAt = null,
    ): void {
        $this->timeline->record(new PaymentTimelineEntry(
            id: $this->identifier->generate(),
            paymentId: $paymentId,
            projectId: $projectId,
            type: $type,
            occurredAt: $occurredAt ?? $this->clock->now(),
            summary: $summary,
            details: $details,
        ));
    }

    /**
     * Drops whatever provider exchanges an earlier, unrelated call left in
     * the journal — call right before the provider call to be attributed.
     */
    public function forgetPendingExchanges(): void
    {
        $this->exchanges->drain();
    }

    /**
     * Attaches every provider exchange made since the last drain to this
     * payment, each at its own time.
     */
    public function recordProviderExchanges(string $paymentId, string $projectId): void
    {
        foreach ($this->exchanges->drain() as $exchange) {
            $this->record(
                $paymentId,
                $projectId,
                PaymentTimelineEntryType::ProviderExchange,
                self::describe($exchange),
                $exchange->toArray(),
                $exchange->occurredAt,
            );
        }
    }

    private static function describe(IntegrationExchange $exchange): string
    {
        $outcome = $exchange->responseStatus === null
            ? 'sans réponse ('.($exchange->error ?? 'erreur réseau').')'
            : 'HTTP '.$exchange->responseStatus;

        return sprintf(
            'Appel %s « %s » : %s %s → %s en %d ms%s',
            $exchange->provider,
            $exchange->operation,
            $exchange->method,
            $exchange->url,
            $outcome,
            $exchange->latencyMs,
            $exchange->attempt > 1 ? sprintf(' (tentative %d)', $exchange->attempt) : '',
        );
    }
}
