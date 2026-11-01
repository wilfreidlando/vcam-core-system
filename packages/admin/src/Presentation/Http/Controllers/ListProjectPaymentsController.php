<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\AuthorizesInScope;
use Core\Identity\Contracts\GetProject;
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
    use AuthorizesInScope;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListPayments $listPayments,
    ) {}

    /**
     * List a Project's payments.
     *
     * Optionally filtered by canonical `status`. Requires `payments:read`.
     */
    public function __invoke(Request $request, string $projectId, OperatorContext $operator): JsonResponse|Response
    {
        $scope = $this->permittedScope($operator, 'payments:read');
        if ($scope === null) {
            return ProblemDetails::forbidden('The payments:read permission is required.');
        }

        // A malformed (non-UUID) projectId would otherwise reach a
        // `WHERE project_id = ?` against a Postgres `uuid` column and
        // crash with an uncaught QueryException — a clean 422 instead
        // (review finding, Lot 6 follow-up).
        Validator::make(['projectId' => $projectId], ['projectId' => 'uuid'])->validate();

        // ADR-0069 §2 / S-04 — the project named in the path: 404 when the
        // account may not see it, 403 when its role there lacks the
        // permission — never an empty 200, which would present a foreign
        // project as an existing, empty one.
        $refusal = $this->projectRefusal($this->getProject, $operator, $projectId, 'payments:read');
        if ($refusal === 'not-found') {
            return ProblemDetails::make('project-not-found', 'Project not found', 404, sprintf('Project %s does not exist.', $projectId));
        }
        if ($refusal === 'forbidden') {
            return ProblemDetails::forbidden('The payments:read permission is required on this project.');
        }

        $statusRaw = $request->query('status');
        $status = is_string($statusRaw) && $statusRaw !== '' ? $statusRaw : null;

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listPayments->list(new ListPaymentsCommand($projectId, $status, $page, $perPage), $scope);

        return new JsonResponse([
            'data' => array_map(static fn ($payment): array => [
                'id' => $payment->id,
                'channel' => $payment->channel,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'requires_action' => $payment->requiresAction,
                'error_message' => $payment->errorMessage,
                'idempotency_key' => $payment->idempotencyKey,
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
