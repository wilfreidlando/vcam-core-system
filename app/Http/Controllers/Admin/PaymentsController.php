<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesProjectNames;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\ListPaymentsCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payments admin: list + detail. Reads the existing `ListPayments`/
 * `GetPaymentStatus` contracts (G3c-compliant — payment admin stays
 * within `Contracts/`).
 *
 * Filters: `project_id` (UUID required), `channel`, `status` (canonical
 * string value, never the enum directly — G3c), `from`, `to`. Unrecognised
 * status / channel are silently ignored (matches the API behaviour).
 */
final class PaymentsController
{
    use ResolvesProjectNames;

    /**
     * The project dropdown fetches one page of `ListProjects` and renders
     * every row as an <option> — same documented-limitation pattern as
     * `ProjectsController::create()`'s organization dropdown (review
     * finding: the project picker used to be a free-text field the
     * operator had to paste a raw UUID into — "on ne voit pas les données
     * qui parlent à l'utilisateur, tu mets les IDs au lieu des noms").
     */
    private const PROJECT_OPTIONS_LIMIT = 100;

    public function __construct(
        private readonly ListPayments $listPayments,
        private readonly GetPaymentStatus $getPaymentStatus,
        private readonly ListProjects $listProjects,
        private readonly GetProject $getProject,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('payments:read')) {
            abort(403, 'La permission payments:read est requise.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $projectId = (string) $request->query('project_id', '');
        $channel = (string) $request->query('channel', '');
        $status = (string) $request->query('status', '');

        // A malformed (non-UUID) project_id would otherwise reach a
        // `WHERE project_id = ?` against a Postgres `uuid` column and
        // crash with an uncaught QueryException (review finding, Lot 6
        // follow-up — same class of bug already fixed on the JSON API).
        if ($projectId !== '') {
            Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();
        }

        $projects = $this->listProjects->list(new ListProjectsCommand(page: 1, perPage: self::PROJECT_OPTIONS_LIMIT));

        // Project is an optional narrowing filter, not a requirement —
        // `ListPayments` lists across every Project when none is given
        // (review finding: this used to force picking a project before
        // showing anything at all; the admin plane is trusted-operator-
        // only, so a platform-wide paginated view is the expected
        // default, the same way payments/webhooks/audit already work).
        $pageResult = $this->listPayments->list(new ListPaymentsCommand(
            projectId: $projectId === '' ? null : $projectId,
            status: $status === '' ? null : $status,
            page: $page,
            perPage: $perPage,
        ));

        // Filter by channel client-side (contract doesn't carry a channel
        // filter on list — the ListPaymentsCommand only takes status).
        $items = array_values(array_filter(
            $pageResult->items,
            static fn ($p) => $channel === '' || $p->channel === $channel,
        ));

        $projectNames = $this->resolveProjectNames(
            $this->getProject,
            array_map(static fn ($p): string => $p->projectId, $items),
        );

        return response()->view('admin.payments.index', [
            'title' => 'Paiements',
            'payments' => $items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => [
                'project_id' => $projectId,
                'channel' => $channel,
                'status' => $status,
            ],
            'projects' => $projects->items,
            'projectNames' => $projectNames,
        ]);
    }

    public function show(string $payment, Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('payments:read')) {
            abort(403, 'La permission payments:read est requise.');
        }

        $projectId = (string) $request->query('project_id', '');
        if ($projectId === '') {
            return redirect()->route('admin.payments.index')->withErrors([
                'project_id' => 'Un project_id est requis pour rechercher un paiement.',
            ]);
        }

        Validator::make(
            ['payment' => $payment, 'project_id' => $projectId],
            ['payment' => 'uuid', 'project_id' => 'uuid'],
        )->validate();

        try {
            $view = $this->getPaymentStatus->get(new GetPaymentStatusCommand(
                paymentId: $payment,
                requestingProjectId: $projectId,
            ));
        } catch (RuntimeException) {
            return redirect()->route('admin.payments.index', ['project_id' => $projectId])->withErrors([
                'payment' => 'Paiement introuvable pour ce projet.',
            ]);
        }

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            $project = null;
        }

        return response()->view('admin.payments.show', [
            'title' => 'Paiement '.substr($view->id, 0, 8).'…',
            'payment' => $view,
            'project' => $project,
            'projectId' => $projectId,
        ]);
    }
}
