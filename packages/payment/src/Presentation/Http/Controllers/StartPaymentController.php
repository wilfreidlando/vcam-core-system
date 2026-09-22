<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Domain\Exception\UnsupportedCurrency;
use Core\Payment\PaymentFlow;
use Core\Payment\Presentation\Http\Requests\StartPaymentRequest;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/payments` — kept for backward compatibility (G9: removing a
 * published route is a breaking change). Reads `flow` from the request
 * body, defaulting to `Payin`, exactly as before. New integrations
 * should prefer the explicit `POST /v1/payments/paylink` or
 * `POST /v1/payments/payin` (user request) — this generic endpoint's
 * behaviour silently branching on a body field is exactly what those
 * two exist to replace.
 */
final class StartPaymentController extends AbstractStartPaymentController
{
    /**
     * Start a payment.
     *
     * The request names only a `channel` (e.g. `mtn_momo`, `card`), never a
     * provider — `StartPaymentUseCase` resolves which adapter actually
     * handles it via the Project's routing configuration (ADR-0038 §2).
     * Requires the `payments:write` scope and an `Idempotency-Key` header
     * (G7, ADR-0025). Crediting a wallet on success is never implicit — it
     * only happens if `wallet_recipient_external_ref` is given (ADR-0037).
     * Prefer `POST /v1/payments/paylink` or `POST /v1/payments/payin`
     * for new integrations.
     *
     * @throws NoProviderForChannelException no provider is routed for the requested `channel` (422)
     * @throws UnsupportedCurrency the requested `currency` isn't in the Core's allow-list (422)
     * @throws ProviderPermanentError|ProviderAuthError the provider rejected the request outright (502)
     * @throws ProviderTransientError|ProviderDisabledException|CircuitOpenException the provider is temporarily unavailable (503)
     */
    public function __invoke(StartPaymentRequest $request, ProjectContext $project): JsonResponse|Response
    {
        return parent::__invoke($request, $project);
    }

    protected function flow(array $data): PaymentFlow
    {
        return isset($data['flow']) && is_string($data['flow']) ? PaymentFlow::from($data['flow']) : PaymentFlow::Payin;
    }
}
