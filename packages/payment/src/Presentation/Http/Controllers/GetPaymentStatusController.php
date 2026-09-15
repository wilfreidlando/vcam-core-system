<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
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
     */
    public function __invoke(string $paymentId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:read')) {
            return ProblemDetails::forbidden('The payments:read scope is required.');
        }

        $view = $this->getPaymentStatus->get(new GetPaymentStatusCommand($paymentId, $project->projectId));

        return new JsonResponse([
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status->value,
            'amount' => $view->amount,
            'currency' => $view->currency,
            'requires_action' => $view->requiresAction,
            'action_hint' => $view->actionHint,
            'payment_url' => $view->paymentUrl,
            'error_message' => $view->errorMessage,
        ]);
    }
}
