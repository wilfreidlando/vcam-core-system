<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Payment\PaymentFlow;
use Core\Payment\Presentation\Http\Requests\StartPaymentRequest;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/payments` — the one public entry point for starting a
 * payment, whatever channel or provider ends up handling it (ADR-0038).
 * The request never names a provider — only a `channel`; routing to the
 * adapter that serves it happens entirely inside `StartPaymentUseCase`.
 *
 * The route's `idempotency` middleware (G7, ADR-0025) already required
 * and validated the `Idempotency-Key` header before this controller
 * ever runs; that same value is reused as `StartPaymentCommand::$idempotencyKey`
 * — one key protects both the cached HTTP response and the underlying
 * provider call, never two independent ones.
 */
final class StartPaymentController
{
    public function __construct(
        private readonly StartPayment $startPayment,
    ) {}

    /**
     * Start a payment.
     *
     * The request names only a `channel` (e.g. `mtn_momo`, `card`), never a
     * provider — `StartPaymentUseCase` resolves which adapter actually
     * handles it via the Project's routing configuration (ADR-0038 §2).
     * Requires the `payments:write` scope and an `Idempotency-Key` header
     * (G7, ADR-0025). Crediting a wallet on success is never implicit — it
     * only happens if `wallet_recipient_external_ref` is given (ADR-0037).
     */
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
            throw new LogicException('StartPaymentController reached without a validated Idempotency-Key header.');
        }

        /** @var array{channel: string, amount: int, currency: string, reason: string, customer_phone_number?: string, customer_name?: string, customer_email?: string, customer_lang?: string, flow?: string, return_url?: string, wallet_recipient_external_ref?: string} $data */
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
            flow: isset($data['flow']) ? PaymentFlow::from($data['flow']) : PaymentFlow::Payin,
            returnUrl: $data['return_url'] ?? null,
            walletRecipientExternalRef: $data['wallet_recipient_external_ref'] ?? null,
        ));

        return new JsonResponse([
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status,
            'amount' => $view->amount,
            'currency' => $view->currency,
            'requires_action' => $view->requiresAction,
            'action_hint' => $view->actionHint,
            'payment_url' => $view->paymentUrl,
        ], 201);
    }
}
