<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Export\Contracts\ExportNotFound;
use Core\Export\Contracts\GetExport;
use Core\Export\Contracts\ListExports;
use Core\Export\Contracts\RequestExport;
use Core\Export\Contracts\RequestExportCommand;
use Core\Export\Contracts\SignedDownloadLinks;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Observability\TraceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Export des données » of a project, in the console (ADR-0088 §3,
 * ADR-0101): ask for the export, list the past ones, download one through a
 * signed link generated at the click.
 *
 * Reserved to the accounts that hold `exports:write` on the project —
 * `org_owner`, `org_admin` and the platform roles. The request is audited by
 * the `export` module itself, with the operator as its author.
 */
final class ProjectExportsController
{
    use ResolvesScopedProject;

    private const PERMISSION = 'exports:write';

    public function __construct(
        private readonly GetProject $getProject,
        private readonly RequestExport $requestExport,
        private readonly ListExports $listExports,
        private readonly GetExport $getExport,
        private readonly SignedDownloadLinks $links,
    ) {}

    public function index(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, self::PERMISSION);
        $this->resolveScopedProject($this->getProject, $operator, $projectId, self::PERMISSION);

        return response()->view('admin.projects.exports', [
            'title' => 'Export des données',
            'projectId' => $projectId,
            'exports' => $this->listExports->list($projectId, 30, $scope),
        ]);
    }

    public function store(OperatorContext $operator, string $projectId): RedirectResponse
    {
        $this->permittedScope($operator, self::PERMISSION);
        $project = $this->scopedProject($this->getProject, $operator, $projectId, self::PERMISSION);

        $this->requestExport->request(new RequestExportCommand(
            projectId: $project->id,
            organizationId: $project->organizationId,
            requestedBy: 'operator:'.$operator->operatorId,
            correlationId: TraceContext::correlationId(),
        ));

        return redirect()->route('admin.projects.exports', $projectId)
            ->with('status', 'Export demandé : il est préparé en arrière-plan, le lien de téléchargement apparaît ici dès qu\'il est prêt.');
    }

    public function download(OperatorContext $operator, string $projectId, string $exportId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, self::PERMISSION);
        $this->resolveScopedProject($this->getProject, $operator, $projectId, self::PERMISSION);
        Validator::make(['export_id' => $exportId], ['export_id' => 'uuid'])->validate();

        try {
            $export = $this->getExport->get($exportId, $scope);
        } catch (ExportNotFound) {
            abort(404, 'Export introuvable.');
        }

        // The export is addressed under a project: it must be that project's.
        if ($export->projectId !== $projectId) {
            abort(404, 'Export introuvable.');
        }

        if (! $export->available) {
            return redirect()->route('admin.projects.exports', $projectId)
                ->withErrors(['resource' => 'Cet export n\'a plus de fichier à télécharger (en préparation, en échec ou supprimé après 7 jours).']);
        }

        // The signed link is generated at the click: fifteen minutes from now.
        return redirect()->away($this->links->forExport($export->id));
    }
}
