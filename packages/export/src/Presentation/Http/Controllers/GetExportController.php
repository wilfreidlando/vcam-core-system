<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Http\Controllers;

use Core\Export\Contracts\ExportNotFound;
use Core\Export\Contracts\GetExport;
use Core\Export\Contracts\SignedDownloadLinks;
use Core\Export\Presentation\Http\ExportJson;
use Core\Export\Presentation\Http\Responses\ExportResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use DateTimeImmutable;
use DateTimeInterface;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/exports/{exportId}` — ADR-0088 §3.
 */
final class GetExportController
{
    public function __construct(
        private readonly GetExport $getExport,
        private readonly SignedDownloadLinks $links,
    ) {}

    /**
     * Get an export's status and its download link.
     *
     * While the export is `queued` or `running`, poll again. When it is
     * `done`, `download_url` is a **signed link valid for 15 minutes**:
     * it needs no API key, so fetch it from your server and do not share
     * it. Each call to this route returns a fresh link as long as the
     * file exists — the file itself is deleted seven days after the
     * request, `expires_at`. A `failed` export carries its `error`.
     *
     * Requires the `exports:write` scope, and returns the same not-found
     * response whether the export doesn't exist or belongs to another
     * project.
     *
     * @throws ExportNotFound the export doesn't exist, or belongs to another project (404)
     */
    #[ScrambleResponse(status: 200, type: ExportResponses::EXPORT, description: 'The export, with a signed download link once it is done.')]
    public function __invoke(string $exportId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('exports:write')) {
            return ProblemDetails::forbidden('The exports:write scope is required.');
        }

        $view = $this->getExport->get($exportId, AccessScope::forProject($project->projectId));

        $link = null;

        if ($view->available) {
            $url = $this->links->forExport($view->id);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $expires = isset($query['expires']) && is_numeric($query['expires']) ? (int) $query['expires'] : null;

            $link = [
                'url' => $url,
                'expires_at' => $expires === null ? '' : (new DateTimeImmutable('@'.$expires))->format(DateTimeInterface::RFC3339),
            ];
        }

        return new JsonResponse(ExportJson::view($view, $link));
    }
}
