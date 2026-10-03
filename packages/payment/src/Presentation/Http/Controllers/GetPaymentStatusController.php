<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/payments/{paymentId}` — reads the Core's own state only (no
 * live reconciliation against the provider yet; see
 * `Core\Payment\Contracts\GetPaymentStatus`).
 */
final class GetPaymentStatusController
{
    public function __construct(
        private readonly GetPaymentStatus $getPaymentStatus,
    ) {}

    /**
     * Get a payment's current status.
     *
     * Reads the Core's own last-known state only — no live call to the
     * provider. Requires the `payments:read` scope, and returns the same
     * not-found response whether the payment doesn't exist or belongs to
     * another project.
     *
     * @throws PaymentNotFound the payment doesn't exist, or belongs to another project (404)
     */
    public function __invoke(string $paymentId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:read')) {
            return ProblemDetails::forbidden('The payments:read scope is required.');
        }

        $view = $this->getPaymentStatus->get(new GetPaymentStatusCommand($paymentId, $project->projectId, $project->environment));

        return new JsonResponse([
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status,
            'amount' => $view->amount,
            'currency' => $view->currency,
            'requires_action' => $view->requiresAction,
            'action_hint' => $view->actionHint,
            'payment_url' => $view->paymentUrl,
            'error_message' => $view->errorMessage,
            // Review finding, user-directed: this is the documented
            // fallback truth source when a webhook is missed — it must
            // hand back the same caller-supplied context the webhook
            // already does (its own Idempotency-Key, and the callback_url
            // it registered), or polling here loses information the
            // webhook has.
            'idempotency_key' => $view->idempotencyKey,
            'callback_url' => $view->callbackUrl,
        ]);
    }
}
