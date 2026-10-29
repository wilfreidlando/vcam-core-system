<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Billing\Contracts\ProjectMetricsView;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0080 §1, §2 — the portfolio: one row per project the operator may see,
 * with the indicators that tell the operator where to look.
 *
 * The permission is `invoices:write`, held by the platform, `org_owner`,
 * `org_admin` and `org_finance` — the roles ADR-0080 §1 names. A support or
 * viewer account never sees what a project earns. Every figure comes from the
 * `ProjectMetrics` port, which requires the operator's scope (G16).
 */
final class PortfolioController
{
    use ResolvesScopedProject;

    private const MAX_PROJECTS = 50;

    public function __construct(
        private readonly ListProjects $listProjects,
        private readonly ProjectMetrics $metrics,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:write');

        $search = $request->query('q');
        $page = $this->listProjects->list(new ListProjectsCommand(
            organizationId: null,
            page: 1,
            perPage: self::MAX_PROJECTS,
            search: is_string($search) && $search !== '' ? $search : null,
        ), $scope);

        $rows = [];
        $totals = ['customers' => 0, 'active' => 0, 'pastDue' => 0, 'mrr' => [], 'collected' => [], 'receivables' => []];

        foreach ($page->items as $project) {
            $metrics = $this->metrics->forProject($project->id, $scope);

            if (! $metrics instanceof ProjectMetricsView) {
                continue;
            }

            $rows[] = ['project' => $project, 'metrics' => $metrics, 'attention' => $this->attention($metrics)];
            $totals['customers'] += $metrics->customers;
            $totals['active'] += $metrics->activeCustomers;
            $totals['pastDue'] += $metrics->pastDue;

            foreach (['mrr' => $metrics->mrrMinor, 'collected' => $metrics->collectedMinor, 'receivables' => $metrics->receivablesMinor] as $key => $figures) {
                foreach ($figures as $currency => $amount) {
                    $totals[$key][$currency] = ($totals[$key][$currency] ?? 0) + $amount;
                }
            }
        }

        // The projects that need a look come first.
        usort($rows, static fn (array $a, array $b): int => count($b['attention']) <=> count($a['attention']));

        return response()->view('admin.portfolio.index', [
            'title' => 'Portefeuille',
            'rows' => $rows,
            'totals' => $totals,
            'search' => is_string($search) ? $search : '',
            'truncated' => $page->total > self::MAX_PROJECTS,
        ]);
    }

    /**
     * What deserves the operator's attention, in words (ADR-0080 §4).
     *
     * @return list<string>
     */
    private function attention(ProjectMetricsView $m): array
    {
        $flags = [];

        if ($m->pastDue > 0) {
            $flags[] = $m->pastDue.' impayé'.($m->pastDue > 1 ? 's' : '');
        }

        if ($m->customersAtLimit > 0) {
            $flags[] = $m->customersAtLimit.' client'.($m->customersAtLimit > 1 ? 's' : '').' à la limite';
        }

        $overdue = 0;
        foreach ($m->receivableAging as $buckets) {
            $overdue += $buckets['d60_plus'];
        }

        if ($overdue > 0) {
            $flags[] = 'créances > 60 jours';
        }

        if ($m->churnBps >= 1000) {
            $flags[] = 'attrition '.number_format($m->churnBps / 100, 1, ',', ' ').' %';
        }

        return $flags;
    }
}
