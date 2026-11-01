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
use Core\Notification\Contracts\ListChannelSessions;
use Core\Notification\Contracts\ListChannelSessionsCommand;
use Core\Notification\Contracts\ListNotifications;
use Core\Notification\Contracts\ListNotificationsCommand;
use Core\Notification\Contracts\NotificationChannel;
use Core\Payment\Contracts\ListProviderApplications;
use Core\Payment\Contracts\ListProviderApplicationsCommand;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Contracts\PortalSettingsView;
use Core\Portal\Domain\Model\PortalSettings;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Webhook\Contracts\ListWebhookDeliveries;
use Core\Webhook\Contracts\ListWebhookDeliveriesCommand;
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

    /** A key that has not been used for that long is not "used recently". */
    private const RECENT_USAGE_DAYS = 30;

    /** The only notification channel the Core carries an adapter for (ADR-0042). */
    private const NOTIFICATION_CHANNEL = 'whatsapp';

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ProjectMetrics $metrics,
        private readonly MetricsHistory $history,
        private readonly ListPlans $plans,
        private readonly ListApiKeys $apiKeys,
        private readonly ListWebhookEndpoints $webhooks,
        private readonly ListWebhookDeliveries $deliveries,
        private readonly ListProviderApplications $providerApplications,
        private readonly ListNotifications $notifications,
        private readonly ListChannelSessions $channelSessions,
        private readonly ManagePortalSettings $portal,
        private readonly ManageBillingSettings $billing,
        private readonly Clock $clock,
    ) {}

    public function show(Request $request, OperatorContext $operator, string $projectId): Response
    {
        // ADR-0080 §1 — this page carries two levels. The **onboarding**
        // checklist belongs to the project: every member of its
        // organisation reads it (`projects:read`). What the project
        // **earns** belongs to the finance level (`invoices:write`) —
        // the same permission as the portfolio.
        $scope = $this->permittedScope($operator, 'projects:read');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'projects:read');

        $moneyScope = $operator->scopeFor('invoices:write');
        $mayReadMoney = $moneyScope->allowsProject($projectId);

        $days = (int) $request->query('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        $points = $mayReadMoney ? $this->history->forProject($projectId, $moneyScope, $days) : [];
        $currencies = array_values(array_unique(array_map(static fn (MetricsPoint $p): string => $p->currency, $points)));
        sort($currencies);

        $checklist = $this->checklist($projectId, $scope);
        $missing = array_values(array_filter(
            $checklist,
            static fn (array $item): bool => $item['mandatory'] && $item['applicable'] && ! $item['done'],
        ));

        return response()->view('admin.billing.indicators', [
            'title' => 'Indicateurs',
            'project' => $project,
            'projectId' => $projectId,
            'mayReadMoney' => $mayReadMoney,
            'metrics' => $mayReadMoney ? $this->metrics->forProject($projectId, $moneyScope) : null,
            'days' => $days,
            'periods' => self::PERIODS,
            'currencies' => $currencies,
            'points' => $points,
            'checklist' => $checklist,
            // ADR-0080 §4 — « Un projet `active` à qui il manque un élément
            // obligatoire pour ce qu'il utilise est signalé. »
            'blocked' => $project->status === 'active' && $missing !== [],
            'missing' => $missing,
        ]);
    }

    /**
     * ADR-0080 §4 — computed from the project's real state, never ticked by hand.
     *
     * An item that does not concern the project is **not applicable**: it is
     * shown as such, never as a failure. An item that concerns it and is not
     * done is **mandatory** when the project cannot work without it — those
     * are the ones that make an active project "not ready yet".
     *
     * @return list<array{label: string, done: bool, applicable: bool, mandatory: bool, hint: string}>
     */
    private function checklist(string $projectId, AccessScope $scope): array
    {
        [$published, $sellsLimits] = $this->publishedPlans($projectId, $scope);
        $keyUsed = $this->apiKeyUsedRecently($projectId, $scope);
        [$webhookActive, $webhookDelivered] = $this->webhook($projectId, $scope);
        $application = $this->providerApplications->list(new ListProviderApplicationsCommand($projectId), $scope) !== [];
        $notifies = $this->notifications->list(new ListNotificationsCommand($projectId), $scope)->items !== [];
        $session = $this->channelSessions->list(
            new ListChannelSessionsCommand($projectId, NotificationChannel::fromString(self::NOTIFICATION_CHANNEL)),
            $scope,
        ) !== [];
        $portal = $this->portal->get($projectId, $scope);
        $billing = $this->billing->get($projectId, $scope);

        return [
            [
                'label' => 'Une clé d\'API a déjà servi',
                'done' => $keyUsed,
                'applicable' => true,
                'mandatory' => true,
                'hint' => 'Le SaaS a appelé la plateforme au moins une fois.',
            ],
            [
                'label' => 'Un webhook actif et testé',
                'done' => $webhookActive && $webhookDelivered,
                'applicable' => true,
                'mandatory' => true,
                'hint' => 'Sans lui, le SaaS ne sait pas quand un abonnement change — et une livraison qui n\'a jamais abouti ne prouve rien.',
            ],
            [
                'label' => 'Une application prestataire enregistrée',
                'done' => $application,
                'applicable' => true,
                'mandatory' => false,
                'hint' => 'Le couple clé publique / clé privée avec lequel le prestataire identifie le projet.',
            ],
            [
                'label' => 'Une offre publiée',
                'done' => $published,
                'applicable' => true,
                'mandatory' => true,
                'hint' => 'Sans offre publiée, personne ne peut s\'abonner.',
            ],
            [
                'label' => 'Un portail personnalisé',
                'done' => $portal !== null && $portal->allowedReturnUrls !== [] && $this->isCustomised($portal),
                'applicable' => $portal !== null,
                'mandatory' => false,
                'hint' => 'Le logo, la couleur et la page de support du projet, et les adresses de retour vers son application.',
            ],
            [
                'label' => 'Une session de notification enregistrée',
                'done' => $session,
                'applicable' => $notifies,
                'mandatory' => false,
                'hint' => 'Le projet envoie des notifications : il lui faut une session de canal. Le lien effectif se vérifie sur la page des sessions.',
            ],
            [
                'label' => 'Attribution des usages obligatoire',
                'done' => $billing !== null && $billing->requireCustomerRef,
                'applicable' => $sellsLimits,
                'mandatory' => true,
                'hint' => 'Une offre publiée porte une limite : sans attribution obligatoire, un client peut contourner son quota (ADR-0074 §6).',
            ],
        ];
    }

    /**
     * The published plans of the project, and whether one of them sells a
     * limit — a limit is a feature of type `limit` on a published version.
     *
     * @return array{0: bool, 1: bool}
     */
    private function publishedPlans(string $projectId, AccessScope $scope): array
    {
        $published = false;
        $sellsLimits = false;

        foreach ($this->plans->list(new ListPlansCommand($projectId), $scope)->items as $plan) {
            foreach ($plan->versions as $version) {
                if ($version->status !== 'published') {
                    continue;
                }

                $published = true;

                foreach ($version->features as $feature) {
                    if (is_array($feature) && ($feature['type'] ?? null) === 'limit') {
                        $sellsLimits = true;
                    }
                }
            }
        }

        return [$published, $sellsLimits];
    }

    /**
     * A key that has served recently — ADR-0080 §4 says « utilisée
     * récemment », not « émise ».
     */
    private function apiKeyUsedRecently(string $projectId, AccessScope $scope): bool
    {
        $since = $this->clock->now()->modify('-'.self::RECENT_USAGE_DAYS.' days');

        foreach ($this->apiKeys->list(new ListApiKeysCommand($projectId), $scope) as $key) {
            if (! $key->active || $key->lastUsedAt === null) {
                continue;
            }

            if ($key->lastUsedAt >= $since) {
                return true;
            }
        }

        return false;
    }

    /**
     * An endpoint that is active, and a delivery that actually landed —
     * ADR-0080 §4 asks for both: « endpoint de webhook enregistré **et**
     * test de livraison réussi ».
     *
     * @return array{0: bool, 1: bool}
     */
    private function webhook(string $projectId, AccessScope $scope): array
    {
        $active = false;

        foreach ($this->webhooks->list(new ListWebhookEndpointsCommand($projectId), $scope) as $endpoint) {
            if ($endpoint->active) {
                $active = true;
                break;
            }
        }

        if (! $active) {
            return [false, false];
        }

        $delivered = $this->deliveries->list(
            new ListWebhookDeliveriesCommand(status: 'delivered', projectId: $projectId, page: 1, perPage: 1),
            $scope,
        )->items !== [];

        return [true, $delivered];
    }

    /**
     * Whether the portal carries the project's own identity rather than the
     * default one.
     */
    private function isCustomised(PortalSettingsView $portal): bool
    {
        return $portal->logoUrl !== null
            || $portal->supportUrl !== null
            || strtolower($portal->primaryColor) !== PortalSettings::DEFAULT_COLOR;
    }
}
