<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Payment\Contracts\ReconcileStuckPaymentsCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * On-demand reconciliation run. A small, capped batch (unlike the
 * scheduled `admin:reconcile-stuck-payments` command, which uses the full
 * configured batch size) so this synchronous HTTP request — which makes
 * up to N outbound `checkStatus()` calls — stays responsive.
 */
final class TriggerPaymentReconciliationController
{
    private const int ON_DEMAND_BATCH_LIMIT = 20;

    public function __construct(
        private readonly ReconcileStuckPayments $reconcileStuckPayments,
    ) {}

    /**
     * Trigger an on-demand payment reconciliation run.
     *
     * Polls `checkStatus()` on a small batch of payments stuck in a
     * non-terminal status, applying any status change through the same
     * path an inbound webhook uses. Requires `payments:reconcile:write`.
     */
    public function __invoke(OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('payments:reconcile:write')) {
            return ProblemDetails::forbidden('The payments:reconcile:write permission is required.');
        }

        $summary = $this->reconcileStuckPayments->reconcile(
            new ReconcileStuckPaymentsCommand(limitOverride: self::ON_DEMAND_BATCH_LIMIT),
        );

        return new JsonResponse([
            'checked' => $summary->checked,
            'updated' => $summary->updated,
            'errors' => $summary->errors,
        ]);
    }
}
