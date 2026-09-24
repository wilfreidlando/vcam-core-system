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
 * `POST /v1/payments/payin` — starts a payment via MyCoolPay's direct,
 * app-driven flow (e.g. a USSD push to the customer's phone) — no
 * hosted link, no browser redirect at all. User request: `callback_url`
 * is how the caller learns the payment settled — there is no
 * `return_url` to fall back on for this flow, so the reliable
 * server-to-server notification matters even more here.
 */
final class StartPayinPaymentController extends AbstractStartPaymentController
{
    /**
     * Start a payment via a direct, app-driven charge.
     *
     * The request names only a `channel` (e.g. `mtn_momo`, `card`), never a provider — the
     * Core resolves which provider handles it for your project. Requires the `payments:write`
     * scope and an `Idempotency-Key` header.
     *
     * **Expect 15 to 20 seconds of latency**: the provider answers only once the customer's
     * operator has been queried. Set your HTTP client timeout to at least 60 seconds, and reuse
     * the same `Idempotency-Key` if you must retry.
     *
     * The customer receives a validation prompt on their phone. When `requires_action` is `true`,
     * `action_hint` is the USSD code to dial if the prompt does not open. There is no browser
     * redirect for this flow — supply `callback_url` to be notified once the payment settles.
     * Crediting a wallet on success is never implicit — it only happens if
     * `wallet_recipient_external_ref` is given.
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
        return PaymentFlow::Payin;
    }
}
