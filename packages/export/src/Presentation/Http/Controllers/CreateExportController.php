<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Http\Controllers;

use Core\Export\Contracts\RequestExport;
use Core\Export\Contracts\RequestExportCommand;
use Core\Export\Presentation\Http\ExportJson;
use Core\Export\Presentation\Http\Responses\ExportResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/exports` — ADR-0088 §3.
 */
final class CreateExportController
{
    public function __construct(
        private readonly RequestExport $requestExport,
    ) {}

    /**
     * Export the calling Project's data.
     *
     * Asks for the export of everything the project produced — customers,
     * plans, subscriptions, invoices, payments, notifications, usage,
     * wallets and their movements, ledger transactions and the trial
     * balance — as one `.zip` of JSON Lines files, one file per type of
     * object, each line in the shape the API returns. The export is built
     * outside the request: the response is `202` with the export in
     * `queued` status; poll `GET /v1/exports/{id}` until it is `done`.
     * The file is kept seven days.
     *
     * Requires the `exports:write` scope and an `Idempotency-Key` header:
     * the same key returns the same export instead of starting another.
     */
    #[ScrambleResponse(status: 202, type: ExportResponses::EXPORT, description: 'The export is accepted and queued.')]
    public function __invoke(ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('exports:write')) {
            return ProblemDetails::forbidden('The exports:write scope is required.');
        }

        $view = $this->requestExport->request(new RequestExportCommand(
            projectId: $project->projectId,
            organizationId: $project->organizationId,
            requestedBy: 'api_key:'.$project->apiKeyId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse(ExportJson::view($view), 202);
    }
}
