<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\ListPaymentsCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ListProjectPaymentsController
{
    public function __construct(
        private readonly ListPayments $listPayments,
    ) {}

    /**
     * List a Project's payments.
     *
     * Optionally filtered by canonical `status`. Requires `payments:read`.
     */
    public function __invoke(Request $request, string $projectId, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('payments:read')) {
            return ProblemDetails::forbidden('The payments:read permission is required.');
        }

        // A malformed (non-UUID) projectId would otherwise reach a
        // `WHERE project_id = ?` against a Postgres `uuid` column and
        // crash with an uncaught QueryException — a clean 422 instead
        // (review finding, Lot 6 follow-up).
        Validator::make(['projectId' => $projectId], ['projectId' => 'uuid'])->validate();

        $statusRaw = $request->query('status');
        $status = is_string($statusRaw) && $statusRaw !== '' ? $statusRaw : null;

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listPayments->list(new ListPaymentsCommand($projectId, $status, $page, $perPage));

        return new JsonResponse([
            'data' => array_map(static fn ($payment): array => [
                'id' => $payment->id,
                'channel' => $payment->channel,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'requires_action' => $payment->requiresAction,
                'error_message' => $payment->errorMessage,
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
