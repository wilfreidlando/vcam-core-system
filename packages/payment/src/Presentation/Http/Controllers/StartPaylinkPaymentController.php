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
 * `POST /v1/payments/paylink` — starts a payment via MyCoolPay's hosted
 * payment link flow: the response's `payment_url` is where the customer
 * is redirected to complete payment. User request, in the same session
 * as `callback_url` (`AbstractStartPaymentController`): a redirect
 * (`return_url`) may never fire (closed tab, lost connection), so the
 * caller should also supply `callback_url` for the reliable,
 * server-to-server "this payment is done, go process it" notification.
 */
final class StartPaylinkPaymentController extends AbstractStartPaymentController
{
    /**
     * Start a payment via a hosted payment link.
     *
     * The request names only a `channel` (e.g. `mtn_momo`, `card`), never a
     * provider — the Core resolves which provider actually
     * handles it via the Project's routing configuration.
     * Requires the `payments:write` scope and an `Idempotency-Key` header.
     * `return_url` is a best-effort browser redirect
     * target only; supply `callback_url` for a reliable server-to-server
     * notification once the payment settles. Crediting a wallet on
     * success is never implicit — it only happens if
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
        return PaymentFlow::Paylink;
    }
}
