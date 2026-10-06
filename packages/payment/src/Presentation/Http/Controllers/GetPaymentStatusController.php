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

        // ADR-0061 — same shape as the creation response, plus `updated_at`.
        // `is_final` tells the caller whether to keep waiting.
        return new JsonResponse([
            'id' => $view->id,
            'status' => $view->status,
            'is_final' => $view->isFinal,
            'amount' => $view->amount,
            'currency' => $view->currency,
            'channel' => $view->channel,
            'created_at' => $view->createdAt,
            'updated_at' => $view->updatedAt,
            ...($view->paymentUrl !== null && ! $view->isFinal ? ['payment_url' => $view->paymentUrl] : []),
            ...($view->actionHint !== null && ! $view->isFinal ? ['action_hint' => $view->actionHint] : []),
            ...($view->isFinal && $view->status !== 'succeeded' && $view->errorMessage !== null ? ['failure_reason' => $view->errorMessage] : []),
        ]);
    }
}
