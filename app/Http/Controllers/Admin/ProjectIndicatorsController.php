<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\MetricsHistory;
use Core\Billing\Contracts\MetricsPoint;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\ListApiKeys;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0080 §2, §4 — the indicators of one project: its figures now, their
 * curves over time, its receivables by age, and the checklist that says whether
 * the project is wired to take money.
 *
 * Read-only. The permission is `invoices:write`, the same as the portfolio:
 * what a project earns is not shown to a support or a viewer account.
 */
final class ProjectIndicatorsController
{
    use ResolvesScopedProject;

    private const PERIODS = [30, 90, 365];

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ProjectMetrics $metrics,
        private readonly MetricsHistory $history,
        private readonly ListPlans $plans,
        private readonly ListApiKeys $apiKeys,
        private readonly ListWebhookEndpoints $webhooks,
        private readonly ManagePortalSettings $portal,
        private readonly ManageBillingSettings $billing,
    ) {}

    public function show(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'invoices:write');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'invoices:write');

        $days = (int) $request->query('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        $points = $this->history->forProject($projectId, $scope, $days);
        $currencies = array_values(array_unique(array_map(static fn (MetricsPoint $p): string => $p->currency, $points)));
        sort($currencies);

        return response()->view('admin.billing.indicators', [
            'title' => 'Indicateurs',
            'project' => $project,
            'projectId' => $projectId,
            'metrics' => $this->metrics->forProject($projectId, $scope),
            'days' => $days,
            'periods' => self::PERIODS,
            'currencies' => $currencies,
            'points' => $points,
            'checklist' => $this->checklist($projectId, $scope),
        ]);
    }

    /**
     * ADR-0080 §4 — computed from the project's real state, never ticked by hand.
     *
     * @return list<array{label: string, done: bool, hint: string}>
     */
    private function checklist(string $projectId, AccessScope $scope): array
    {
        $published = false;

        foreach ($this->plans->list(new ListPlansCommand($projectId), $scope)->items as $plan) {
            foreach ($plan->versions as $version) {
                $published = $published || $version->status === 'published';
            }
        }

        $keyUsed = false;

        foreach ($this->apiKeys->list(new ListApiKeysCommand($projectId), $scope) as $key) {
            $keyUsed = $keyUsed || ($key->active && $key->lastUsedAt !== null);
        }

        $webhook = false;

        foreach ($this->webhooks->list(new ListWebhookEndpointsCommand($projectId), $scope) as $endpoint) {
            $webhook = $webhook || $endpoint->active;
        }

        $portal = $this->portal->get($projectId, $scope);
        $billing = $this->billing->get($projectId, $scope);

        return [
            ['label' => 'Une clé d\'API a déjà servi', 'done' => $keyUsed, 'hint' => 'Le SaaS a appelé la plateforme au moins une fois.'],
            ['label' => 'Un webhook actif', 'done' => $webhook, 'hint' => 'Sans lui, le SaaS ne sait pas quand un abonnement change.'],
            ['label' => 'Une offre publiée', 'done' => $published, 'hint' => 'Sans offre publiée, personne ne peut s\'abonner.'],
            ['label' => 'Des adresses de retour du portail', 'done' => $portal !== null && $portal->allowedReturnUrls !== [], 'hint' => 'Le client revient dans le SaaS après le portail.'],
            ['label' => 'Attribution des usages obligatoire', 'done' => $billing !== null && $billing->requireCustomerRef, 'hint' => 'Chaque usage doit nommer son client pour être compté.'],
        ];
    }
}
