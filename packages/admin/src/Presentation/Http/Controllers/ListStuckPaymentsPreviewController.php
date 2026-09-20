<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ListStuckPayments;
use Core\Payment\Contracts\ListStuckPaymentsCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A pure read — lets an operator preview which payments a reconciliation
 * run would touch before triggering `TriggerPaymentReconciliationController`.
 * Never checks the provider, never mutates state.
 */
final class ListStuckPaymentsPreviewController
{
    private const int ON_DEMAND_BATCH_LIMIT = 20;

    public function __construct(
        private readonly ListStuckPayments $listStuckPayments,
    ) {}

    /**
     * Preview payments currently stuck in a non-terminal status.
     *
     * Requires `payments:reconcile:write` — the same permission
     * `TriggerPaymentReconciliationController` uses, since seeing what
     * would be touched is part of the same operator capability.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('payments:reconcile:write')) {
            return ProblemDetails::forbidden('The payments:reconcile:write permission is required.');
        }

        $limit = min(self::ON_DEMAND_BATCH_LIMIT, max(1, (int) $request->query('limit', (string) self::ON_DEMAND_BATCH_LIMIT)));

        $stuck = $this->listStuckPayments->list(new ListStuckPaymentsCommand($limit));

        return new JsonResponse([
            'data' => array_map(static fn ($payment): array => [
                'id' => $payment->id,
                'project_id' => $payment->projectId,
                'channel' => $payment->channel,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ], $stuck),
        ]);
    }
}
