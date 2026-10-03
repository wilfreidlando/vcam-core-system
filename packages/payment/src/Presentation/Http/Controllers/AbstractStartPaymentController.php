<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Application\Service\PaymentJourney;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Payment\Domain\Model\PaymentTimelineEntryType;
use Core\Payment\PaymentFlow;
use Core\Payment\Presentation\Http\Requests\StartPaymentRequest;
use Core\Support\Clock;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared body for every "start a payment" entry point. Concrete
 * controllers differ only in which `PaymentFlow` they force — user
 * request: two distinct, self-documenting endpoints (a hosted payment
 * link vs. a direct app-driven charge) rather than one generic endpoint
 * whose behaviour silently branches on a `flow` field, so the OpenAPI
 * doc and the route itself say what each one does.
 *
 * Each concrete subclass repeats this method's `@throws` tags on its own
 * `__invoke()` override rather than inheriting this docblock — Scramble's
 * static analysis doesn't trace exception types through a `parent::`
 * call, only reads them from the exact method the route points at
 * (confirmed empirically: tags placed only here never showed up in a
 * regenerated `openapi/v1.json`).
 */
abstract class AbstractStartPaymentController
{
    public function __construct(
        private readonly StartPayment $startPayment,
        private readonly PaymentJourney $journey,
        private readonly Clock $clock,
    ) {}

    public function __invoke(StartPaymentRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:write')) {
            return ProblemDetails::forbidden('The payments:write scope is required.');
        }

        $idempotencyKey = $request->header(RequireIdempotencyKey::HEADER);
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            // Unreachable in practice — the `idempotency` middleware
            // already rejected the request otherwise — but the type
            // system doesn't know that, and failing loudly here is
            // cheaper than trusting it silently.
            throw new LogicException(static::class.' reached without a validated Idempotency-Key header.');
        }

        $receivedAt = $this->clock->now();

        /** @var array{channel: string, amount: int, currency: string, reason: string, customer_phone_number?: string, customer_name?: string, customer_email?: string, customer_lang?: string, return_url?: string, wallet_recipient_external_ref?: string, callback_url?: string} $data */
        $data = $request->validated();

        $view = $this->startPayment->start(new StartPaymentCommand(
            projectId: $project->projectId,
            channel: PaymentChannel::fromString($data['channel']),
            amount: $data['amount'],
            currency: $data['currency'],
            reason: $data['reason'],
            idempotencyKey: $idempotencyKey,
            customerPhoneNumber: $data['customer_phone_number'] ?? null,
            customerName: $data['customer_name'] ?? null,
            customerEmail: $data['customer_email'] ?? null,
            customerLang: $data['customer_lang'] ?? null,
            flow: $this->flow($data),
            returnUrl: $data['return_url'] ?? null,
            walletRecipientExternalRef: $data['wallet_recipient_external_ref'] ?? null,
            callbackUrl: $data['callback_url'] ?? null,
        ));

        $body = [
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status,
            'amount' => $view->amount,
            'currency' => $view->currency,
            'requires_action' => $view->requiresAction,
            'action_hint' => $view->actionHint,
            'payment_url' => $view->paymentUrl,
            // The same value the caller sent as its Idempotency-Key
            // header — handed back so a caller can confirm which of its
            // own transactions this response is for without a separate
            // side-lookup.
            'idempotency_key' => $view->idempotencyKey,
            'callback_url' => $view->callbackUrl,
            // Shown once, on this response only — never re-derivable
            // afterwards (same pattern as an API key or a webhook
            // endpoint's secret). Absent entirely when no callback_url
            // was supplied.
            ...($view->callbackSecret !== null ? ['callback_secret' => $view->callbackSecret] : []),
        ];

        $this->recordExchange($request, $data, $body, $receivedAt, $view->id, $project->projectId);

        return new JsonResponse($body, 201);
    }

    /**
     * @param array<string, mixed> $data The request's validated body —
     *                                   `StartPaymentController` (the legacy generic endpoint) still honours
     *                                   a caller-supplied `flow` field from it; the two explicit endpoints
     *                                   ignore it entirely, since the route itself is now the source of
     *                                   truth for which flow this is.
     */
    abstract protected function flow(array $data): PaymentFlow;

    /**
     * ADR-0059 — the integrator's own request and the Core's answer, as the
     * first and last steps of the payment's "Parcours" initiation.
     *
     * @param array<string, mixed> $data validated request body
     * @param array<string, mixed> $body response body
     */
    private function recordExchange(StartPaymentRequest $request, array $data, array $body, DateTimeImmutable $receivedAt, string $paymentId, string $projectId): void
    {
        $this->journey->record(
            $paymentId,
            $projectId,
            PaymentTimelineEntryType::ApiRequestReceived,
            sprintf('Requête %s %s reçue de l\'intégrateur', $request->getMethod(), '/'.ltrim($request->path(), '/')),
            [
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->path(), '/'),
                'idempotency_key' => $request->header(RequireIdempotencyKey::HEADER),
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'body' => $data,
            ],
            $receivedAt,
        );

        if (isset($body['callback_secret'])) {
            // Shown once to the integrator, never stored in clear anywhere else.
            $body['callback_secret'] = '***REDACTED***';
        }

        $this->journey->record(
            $paymentId,
            $projectId,
            PaymentTimelineEntryType::ApiResponseSent,
            'Réponse HTTP 201 envoyée à l\'intégrateur',
            ['status' => 201, 'body' => $body],
        );
    }
}
