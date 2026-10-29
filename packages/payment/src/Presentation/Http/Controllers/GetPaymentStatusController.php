<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Presentation\Http\Presenters\PaymentJson;
use Core\Payment\Presentation\Http\Responses\PaymentResponses;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
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
    #[ScrambleResponse(status: 200, type: PaymentResponses::PAYMENT, description: 'The current status of the payment.')]
    public function __invoke(string $paymentId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:read')) {
            return ProblemDetails::forbidden('The payments:read scope is required.');
        }

        // The public API's scope is the calling project itself
        // (ADR-0069 §2 — the port always takes a scope).
        $view = $this->getPaymentStatus->get(
            new GetPaymentStatusCommand($paymentId, $project->projectId),
            AccessScope::forProject($project->projectId),
        );

        // ADR-0061 — same shape as the creation response, plus `updated_at`.
        // `is_final` tells the caller whether to keep waiting.
        return new JsonResponse(PaymentJson::status($view));
    }
}
