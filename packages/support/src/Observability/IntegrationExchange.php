<?php

declare(strict_types=1);

namespace Core\Support\Observability;

use DateTimeImmutable;

/**
 * One HTTP exchange with an external provider, already redacted (secrets
 * never reach this object). Collected by `ExchangeJournal` so the calling
 * module can attach it to a business timeline — e.g. the payment "Parcours"
 * shows the exact request sent to MyCoolPay and what it answered (ADR-0059).
 */
final readonly class IntegrationExchange
{
    /**
     * @param array<string, string> $requestHeaders redacted
     */
    public function __construct(
        public string $provider,
        public string $operation,
        public string $method,
        public string $url,
        public array $requestHeaders,
        public mixed $requestBody,
        public ?int $responseStatus,
        public mixed $responseBody,
        public int $latencyMs,
        public int $attempt,
        public ?string $error,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'operation' => $this->operation,
            'attempt' => $this->attempt,
            'request' => [
                'method' => $this->method,
                'url' => $this->url,
                'headers' => $this->requestHeaders,
                'body' => $this->requestBody,
            ],
            'response' => [
                'status' => $this->responseStatus,
                'body' => $this->responseBody,
            ],
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
            'occurred_at' => $this->occurredAt->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
