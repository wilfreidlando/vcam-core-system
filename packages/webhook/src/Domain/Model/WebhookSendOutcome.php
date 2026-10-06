<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

use Core\Support\Http\HttpStatusClassifier;

/**
 * Result of one HTTP attempt to a Project's endpoint — the port-level
 * return type for `WebhookSender`, never the raw `ExternalResponse` (that
 * type belongs to `Core\Support\Integration`, and Domain stays pure).
 *
 * `isPermanentFailure` (ADR-0055) is computed by the sender at
 * construction time from `httpStatus` via `HttpStatusClassifier`, so
 * `DeliverWebhookUseCase` and `WebhookRetryPolicy` never have to
 * re-derive the classification. A `4xx` other than `408`/`429` is a
 * caller-side defect; no amount of retry will fix it, so the policy
 * skips the retry budget and the delivery goes straight to the DLQ.
 */
final readonly class WebhookSendOutcome
{
    private function __construct(
        public bool $success,
        public ?int $httpStatus,
        public ?string $error,
        // Null when no response was ever received (the request never
        // reached the endpoint — DNS/connect/timeout failure), present
        // otherwise, success or not — an operator diagnosing a dead
        // delivery needs to see exactly what the endpoint sent back, not
        // just that it failed.
        public ?string $responseBody = null,
        public bool $isPermanentFailure = false,
        // ADR-0059 — what was actually sent (signature masked) and how long it took.
        /** @var array<string, string> */
        public array $requestHeaders = [],
        public string $requestBody = '',
        public ?int $durationMs = null,
    ) {}

    /**
     * @param array<string, string> $requestHeaders
     */
    public function withRequest(array $requestHeaders, string $requestBody, ?int $durationMs): self
    {
        return new self($this->success, $this->httpStatus, $this->error, $this->responseBody, $this->isPermanentFailure, $requestHeaders, $requestBody, $durationMs);
    }

    public static function success(int $httpStatus, ?string $responseBody): self
    {
        return new self(true, $httpStatus, null, $responseBody, false);
    }

    public static function failure(?int $httpStatus, string $error, ?string $responseBody = null): self
    {
        return new self(
            false,
            $httpStatus,
            $error,
            $responseBody,
            HttpStatusClassifier::isPermanentFailure($httpStatus),
        );
    }
}
