<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The console's breadcrumb (« fil d'Ariane »): where the operator is, and the
 * way back up, on every page.
 *
 * It is derived from the route, not written page by page, so a new page shows
 * up in the trail by naming its route. It lives in a composer, not in the
 * template, for the same reason as the sidebar (ADR-0069 §2, guard G16): the
 * project's name is read through a port, which must receive the operator's
 * scope and which a template may not call.
 *
 * Each crumb is `[label, url]`; the last one has no link (it is the page).
 */
final readonly class AdminBreadcrumbComposer
{
    /**
     * Top-level sections: route-name prefix (after `admin.`) → label and index route.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SECTIONS = [
        'audit-log' => ['Journal d\'audit', 'admin.audit-log.index'],
        'organizations' => ['Organisations', 'admin.organizations.index'],
        'portfolio' => ['Portefeuille', 'admin.portfolio'],
        'payments' => ['Paiements', 'admin.payments.index'],
        'webhook-deliveries' => ['Webhooks', 'admin.webhook-deliveries.dead.web'],
        'webhook-events' => ['Webhooks', 'admin.webhook-deliveries.dead.web'],
        'reconciliation' => ['Réconciliation', 'admin.reconciliation.new'],
        'money-exceptions' => ['Exceptions d\'argent', 'admin.money-exceptions.index'],
        'ledger-adjustments' => ['Corrections d\'exploitation', 'admin.ledger-adjustments.index'],
        'channel-sessions' => ['Sessions WhatsApp', 'admin.channel-sessions.index'],
        'operators' => ['Opérateurs', 'admin.operators.index'],
        // ADR-0113 — le guide de l'opérateur.
        'guide' => ['Guide', 'admin.guide.index'],
    ];

    /**
     * The page under a section, by the last segment(s) of the route name.
     *
     * @var array<string, string>
     */
    private const LEAVES = [
        'new' => 'Nouveau',
        'create' => 'Nouveau',
        'store' => 'Nouveau',
        'edit' => 'Modifier',
        'update' => 'Modifier',
        'update.web' => 'Modifier',
        'show' => 'Détail',
        'dead.web' => 'Livraisons en échec',
        'members.index' => 'Membres',
        'members.invite' => 'Inviter un membre',
        'members.store' => 'Membres',
        'run' => 'Résultat',
        'run-one' => 'Résultat',
    ];

    /**
     * Pages of a project, by the route name after `projects.`: a label, or a
     * label with a parent page of the same project (`[label, parent route, parent label]`).
     *
     * @var array<string, string|array{0: string, 1: string, 2: string}>
     */
    private const PROJECT_PAGES = [
        'edit' => 'Modifier',
        'update' => 'Modifier',
        'wallets.index' => 'Wallets',
        'wallets.ledger' => ['Écritures', 'admin.projects.wallets.index', 'Wallets'],
        'notifications.index' => 'Notifications',
        'notifications.show' => ['Détail', 'admin.projects.notifications.index', 'Notifications'],
        'channel-sessions.index' => 'Sessions WhatsApp',
        'provider-applications.create' => 'Applications prestataire',
        'provider-applications.edit' => 'Applications prestataire',
        'webhook-endpoints.index' => 'Endpoints webhook',
        'webhook-endpoints.create' => 'Endpoints webhook',
        'api-keys.create' => 'Clés API',
        'portal' => 'Portail client',
        'commerce-settings' => 'Réglages du commerce',
        'usage' => 'Usage et quotas',
        'indicators' => 'Indicateurs',
        'receivables.index' => 'Créances',
        'ledger' => 'Grand livre',
        'exports' => 'Export des données',
        'customers.index' => 'Clients',
        'customers.new' => ['Nouveau client', 'admin.projects.customers.index', 'Clients'],
        'customers.show' => ['{externalRef}', 'admin.projects.customers.index', 'Clients'],
        'customers.edit' => ['Modifier {externalRef}', 'admin.projects.customers.index', 'Clients'],
        'plans.index' => 'Offres',
        'plans.new' => ['Nouvelle offre', 'admin.projects.plans.index', 'Offres'],
        'subscriptions.index' => 'Abonnements',
        'subscriptions.show' => ['Détail', 'admin.projects.subscriptions.index', 'Abonnements'],
        'invoices.index' => 'Factures',
        'invoices.new' => ['Nouvelle facture', 'admin.projects.invoices.index', 'Factures'],
        'invoices.show' => ['Détail', 'admin.projects.invoices.index', 'Factures'],
        'receipts.index' => ['Encaissements hors ligne', 'admin.projects.invoices.index', 'Factures'],
    ];

    public function __construct(
        private Container $container,
    ) {}

    public function compose(View $view): void
    {
        if (! $this->container->bound(OperatorContext::class)) {
            return;
        }

        $request = $this->container->make(Request::class);
        $name = $request->route()?->getName();

        if (! is_string($name) || ! str_starts_with($name, 'admin.')) {
            return;
        }

        $view->with('breadcrumbs', $this->trail($request, substr($name, 6)));
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    private function trail(Request $request, string $route): array
    {
        if ($route === 'dashboard') {
            return [];
        }

        $trail = [['Tableau de bord', route('admin.dashboard')]];

        if ($route === 'projects.index' || $route === 'projects.new' || $route === 'projects.store') {
            $trail[] = ['Projets', $route === 'projects.index' ? null : route('admin.projects.index')];

            return $route === 'projects.index' ? $trail : [...$trail, ['Nouveau projet', null]];
        }

        if (str_starts_with($route, 'projects.')) {
            return $this->projectTrail($request, $trail, substr($route, 9));
        }

        foreach (self::SECTIONS as $prefix => [$label, $index]) {
            if ($route === $prefix.'.index' || $route === $prefix) {
                return [...$trail, [$label, null]];
            }

            // A creation form's answer page is the section's own listing (it shows the one-time secret).
            if (str_starts_with($route, $prefix.'.') && substr($route, strlen($prefix) + 1) === 'store') {
                return [...$trail, [$label, null]];
            }

            if (str_starts_with($route, $prefix.'.')) {
                $trail[] = [$label, route($index)];
                $leaf = $this->leaf($route, $prefix);
                $trail[] = [$this->routeValue($request, $leaf), null];

                return $trail;
            }
        }

        return $trail;
    }

    /**
     * @param list<array{0: string, 1: string|null}> $trail
     * @return list<array{0: string, 1: string|null}>
     */
    private function projectTrail(Request $request, array $trail, string $page): array
    {
        $trail[] = ['Projets', route('admin.projects.index')];

        $projectId = $request->route('projectId');
        $project = is_string($projectId) ? $projectId : null;

        if ($page === 'show' || $project === null) {
            $trail[] = [$project === null ? 'Projet' : $this->projectName($project), null];

            return $trail;
        }

        $trail[] = [$this->projectName($project), route('admin.projects.show', $project)];

        // Sub-resources of an existing page (a plan version, a customer action…) fall back to their family.
        $def = self::PROJECT_PAGES[$page] ?? self::PROJECT_PAGES[$this->family($page)] ?? null;

        if ($def === null) {
            return $trail;
        }

        if (is_string($def)) {
            $trail[] = [$def, null];

            return $trail;
        }

        $trail[] = [$def[2], route($def[1], $project)];
        $trail[] = [$this->routeValue($request, $def[0]), null];

        return $trail;
    }

    /** `customers.subscribe.web` → `customers.index`; `plans.versions.add` → `plans.index`. */
    private function family(string $page): string
    {
        $head = explode('.', $page)[0];

        return in_array($head, ['customers', 'plans', 'subscriptions', 'invoices', 'wallets', 'notifications', 'channel-sessions', 'webhook-endpoints', 'api-keys', 'provider-applications', 'receipts'], true)
            ? $head.(in_array($head, ['webhook-endpoints', 'api-keys', 'provider-applications'], true) ? '.create' : '.index')
            : $page;
    }

    private function leaf(string $route, string $prefix): string
    {
        $rest = substr($route, strlen($prefix) + 1);

        return self::LEAVES[$rest] ?? self::LEAVES[explode('.', $rest)[0]] ?? 'Détail';
    }

    /** Replaces `{externalRef}`-style placeholders with the route's own value. */
    private function routeValue(Request $request, string $label): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($request): string {
            $value = $request->route($m[1]);

            return is_string($value) ? $value : '';
        }, $label);
    }

    private function projectName(string $projectId): string
    {
        try {
            $operator = $this->container->make(OperatorContext::class);

            return $this->container->make(GetProject::class)->get(new GetProjectCommand($projectId), $operator->scope)->name;
        } catch (Throwable) {
            return 'Projet';
        }
    }
}
