<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderGateway;

/**
 * Port implemented by every payment provider adapter.
 *
 * Extends the generic {@see ProviderGateway} so the integration
 * foundation can index, dispatch and lifecycle-manage the gateway
 * without knowing the payment-specific surface.
 *
 * The adapter is the ONLY place that knows the provider's API shape.
 * Everything above (use cases, controllers, presentation) talks to
 * the generic `PaymentGateway` and the canonical `PaymentReceipt`.
 */
interface PaymentGateway extends ProviderGateway
{
    /**
     * Start a payment at the provider.
     *
     * Returns a `PaymentReceipt` carrying the provider-side
     * `transactionRef` and the canonical status. When the provider
     * requires an out-of-band action (OTP, 3DS, etc.), the receipt
     * carries `requiresAction = true` and `actionHint`.
     */
    public function initiate(InitiatePaymentCommand $command): PaymentReceipt;

    /**
     * Submit the customer's response to the provider's authentication
     * prompt (typically the OTP sent by SMS). Returns a new
     * `PaymentReceipt` reflecting the post-authentication state.
     */
    public function submitAction(PaymentAction $action): PaymentReceipt;

    /**
     * Look up the current canonical status of a previously-initiated
     * payment at the provider. Used by background reconciliation when
     * no webhook has arrived within the expected window.
     */
    public function checkStatus(string $transactionRef): PaymentReceipt;

    /**
     * The provider code identifying this gateway. Inherited from
     * ProviderGateway — kept here for clarity.
     */
    public function providerCode(): ProviderCode;
}
