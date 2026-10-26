<?php

declare(strict_types=1);

namespace App\Http\View\Composers;

use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\CountStuckPayments;
use Core\Payment\Contracts\ListMoneyExceptions;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\View\View;
use Throwable;

/**
 * The console sidebar: which entries the account sees, and the badge
 * counts next to them.
 *
 * It lives here, not inline in the Blade layout, on purpose (ADR-0069 §2):
 * a view is invisible to static analysis, so a port called from a template
 * escapes guard G16 (`AdminQueriesAreScopedRule`) — this composer is in the
 * rule's console namespaces, and `tests/Architecture/ConsoleViewsCallNoPortTest`
 * forbids templates from calling a port directly.
 *
 * Every entry follows the per-organization permission model: an entry is
 * shown when the account holds the permission in at least one of its
 * organizations, and a badge counts exactly what that permission opens.
 * The platform-reserved entries (console accounts, queues, global channel
 * sessions) need a platform role.
 */
final readonly class AdminSidebarComposer
{
    public function __construct(
        private Container $container,
    ) {}

    public function compose(View $view): void
    {
        if (! $this->container->bound(OperatorContext::class)) {
            return;
        }

        $operator = $this->container->make(OperatorContext::class);

        $anywhere = static fn (string $permission): bool => $operator->hasPermissionAnywhere($permission);

        $canWebhooks = $anywhere('webhooks:read');
        $canMoneyExceptions = $anywhere('money-exceptions:read');
        $canReconciliation = $anywhere('payments:reconcile:write');

        $view->with([
            'operator' => $operator,
            'canDashboard' => $anywhere('audit:read'),
            'canOrganizations' => $anywhere('projects:read'),
            'canProjects' => $anywhere('projects:read'),
            'canOperators' => $operator->hasPermission('operators:read'),
            'canPayments' => $anywhere('payments:read'),
            'canWallets' => self::holdsTogether($operator, 'wallets:read', 'projects:read'),
            'canReconciliation' => $canReconciliation,
            'canMoneyExceptions' => $canMoneyExceptions,
            'canWebhooks' => $canWebhooks,
            // ADR-0067 — Horizon can retry and delete jobs: platform only.
            'canQueues' => $operator->hasPermission('queues:manage'),
            // The sidebar entry opens the global sessions, reserved to the
            // platform; a project's sessions are reached from its page.
            'canSessions' => $operator->hasPermission('channel-sessions:write'),
            'canAudit' => $anywhere('audit:read'),
            'deadWebhookCount' => $canWebhooks ? $this->deadWebhookCount($operator) : null,
            'moneyExceptionsCount' => $canMoneyExceptions ? $this->moneyExceptionsCount($operator) : null,
            'stuckPaymentsCount' => $canReconciliation ? $this->stuckPaymentsCount($operator) : null,
        ]);
    }

    /**
     * True when the account holds **every** one of the permissions, in
     * the same organization (ADR-0069 §2 — the permission is the one that
     * decides, in the organization of the resource).
     *
     * The console's own navigation needs it: the "Wallets" entry is a
     * shortcut to the project list (wallets are always project-scoped —
     * there is no global wallet list), and that list needs
     * `projects:read`. Gating the entry on `wallets:read` alone gave an
     * `org_finance` — which holds `wallets:read` but not `projects:read`
     * — a link to a page it cannot open.
     */
    private static function holdsTogether(OperatorContext $operator, string ...$permissions): bool
    {
        if ($operator->isPlatformRole()) {
            foreach ($permissions as $permission) {
                if (! $operator->hasPermission($permission)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($operator->scope->organizationIds as $organizationId) {
            $held = true;

            foreach ($permissions as $permission) {
                if (! $operator->scope->hasPermission($organizationId, $permission)) {
                    $held = false;
                    break;
                }
            }

            if ($held) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cheap, best-effort badge counts: a failed count hides the badge
     * rather than erroring the whole page. `perPage: 1` makes the dead
     * deliveries lookup a single-row query.
     */
    private function deadWebhookCount(OperatorContext $operator): ?int
    {
        try {
            return $this->container->make(ListDeadWebhookDeliveries::class)
                ->list(new ListDeadWebhookDeliveriesCommand(projectId: null, page: 1, perPage: 1), $operator->scopeFor('webhooks:read'))
                ->total;
        } catch (Throwable) {
            return null;
        }
    }

    private function moneyExceptionsCount(OperatorContext $operator): ?int
    {
        try {
            return $this->container->make(ListMoneyExceptions::class)
                ->countUnresolved($operator->scopeFor('money-exceptions:read'));
        } catch (Throwable) {
            return null;
        }
    }

    private function stuckPaymentsCount(OperatorContext $operator): ?int
    {
        try {
            return $this->container->make(CountStuckPayments::class)
                ->count($operator->scopeFor('payments:reconcile:write'));
        } catch (Throwable) {
            return null;
        }
    }
}
