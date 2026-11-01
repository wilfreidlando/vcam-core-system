<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Presentation\Http\Controllers\Concerns\ReadsListParameters;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/plans?status=&cursor=&limit=` — ADR-0071 §5, the catalogue
 * of one project.
 *
 * A plan carries its versions: the plan is stable, its versions carry
 * the terms (ADR-0071 §1).
 */
final class ListPlansController
{
    use ReadsListParameters;
    use SerializesBillingViews;

    public function __construct(
        private readonly ListPlans $listPlans,
    ) {}

    /**
     * List the calling Project's plan catalogue.
     *
     * The listing is paginated by an opaque cursor: pass `next_cursor`
     * back as `cursor` to read the next page, and `null` when there is
     * no more. `status` narrows it to one status — `draft`, `active` or
     * `archived`; absent, every status is listed.
     *
     * Requires the `plans:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::PLAN_PAGE, description: 'A page of plans, and the cursor of the next one.')]
    public function __invoke(Request $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('plans:read')) {
            return ProblemDetails::forbidden('The plans:read scope is required.');
        }

        $page = $this->listPlans->list(
            new ListPlansCommand(
                projectId: $project->projectId,
                status: $this->stringQuery($request, 'status'),
                cursor: $this->listCursor($request),
                limit: $this->listLimit($request),
            ),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: the catalogue never crosses the projects.
            AccessScope::forProject($project->projectId),
        );

        return new JsonResponse([
            'data' => array_map(
                fn (PlanView $view): array => $this->planView($view),
                $page->items,
            ),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
