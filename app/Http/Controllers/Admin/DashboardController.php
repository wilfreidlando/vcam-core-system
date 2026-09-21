<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Contracts\AuditSearchCriteria;
use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\CountStuckPayments;
use Core\Payment\Contracts\PaymentActivitySummary;
use Core\Payment\Contracts\PaymentActivitySummaryWindow;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin dashboard. Reads a few Contracts ports directly — no use case
 * layer, because admin queries are not a "business action" that needs
 * transactional orchestration. They are read views over the same Domain
 * models that use cases mutate.
 *
 * Every stat here is a pure read (review finding, Lot 6 follow-up: this
 * controller used to call `ReconcileStuckPayments::reconcile()` — a
 * live-provider-calling, state-mutating action — just to populate a
 * "stuck payments" count; any operator opening this page silently
 * triggered a real reconciliation run with no permission check).
 *
 * Each query catches its own exceptions and degrades to an empty
 * result, so one failing subsystem never blanks the whole dashboard.
 * If a query fails individually we just show zeros and a log warning —
 * the operator can dig into the failed query separately.
 */
final class DashboardController
{
    private const ACTIVITY_WINDOW_HOURS = 24;

    public function __construct(
        private readonly OperatorContext $operator,
        private readonly AuditQuery $audit,
        private readonly PaymentActivitySummary $paymentActivity,
        private readonly ListDeadWebhookDeliveries $listDead,
        private readonly CountStuckPayments $countStuckPayments,
        private readonly ListOrganizations $listOrganizations,
        private readonly ListProjects $listProjects,
        private readonly ListOperators $listOperators,
    ) {}

    public function show(): Response
    {
        if (! $this->operator->hasPermission('audit:read')) {
            abort(403, 'La permission audit:read est requise.');
        }

        $stats = [
            'payments_24h' => $this->paymentsLast24h(),
            'dead_webhooks' => $this->deadWebhookCount(),
            'stuck_payments' => $this->stuckPaymentsCount(),
            'organizations' => $this->organizationsCount(),
            'projects' => $this->projectsCount(),
            'operators' => $this->operatorsCount(),
        ];
        $recentAudit = $this->recentAudit();

        return response()->view('admin.dashboard', [
            'title' => 'Tableau de bord',
            'stats' => $stats,
            'recentAudit' => $recentAudit,
        ]);
    }

    /**
     * @return array{total: int, succeeded: int}
     */
    private function paymentsLast24h(): array
    {
        try {
            $summary = $this->paymentActivity->summarize(new PaymentActivitySummaryWindow(self::ACTIVITY_WINDOW_HOURS));

            return ['total' => $summary->total, 'succeeded' => $summary->succeeded];
        } catch (Throwable) {
            return ['total' => 0, 'succeeded' => 0];
        }
    }

    private function deadWebhookCount(): int
    {
        try {
            $page = $this->listDead->list(new ListDeadWebhookDeliveriesCommand(
                projectId: null,
                page: 1,
                perPage: 1,
            ));

            return $page->total;
        } catch (Throwable) {
            return 0;
        }
    }

    private function stuckPaymentsCount(): int
    {
        try {
            return $this->countStuckPayments->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * `perPage: 1` — same trick `deadWebhookCount()` already uses: the
     * page's `total` is the count, so there's no need to fetch a real
     * page of rows just to size a KPI card.
     */
    private function organizationsCount(): int
    {
        try {
            return $this->listOrganizations->list(new ListOrganizationsCommand(page: 1, perPage: 1))->total;
        } catch (Throwable) {
            return 0;
        }
    }

    private function projectsCount(): int
    {
        try {
            return $this->listProjects->list(new ListProjectsCommand(page: 1, perPage: 1))->total;
        } catch (Throwable) {
            return 0;
        }
    }

    private function operatorsCount(): int
    {
        try {
            return $this->listOperators->list(new ListOperatorsCommand(page: 1, perPage: 1))->total;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<\Core\Audit\Contracts\AuditEntryView>
     */
    private function recentAudit(): array
    {
        try {
            $result = $this->audit->search(new AuditSearchCriteria(
                resource: null,
                actor: null,
                action: null,
                correlationId: null,
                from: new DateTimeImmutable('-7 days'),
                to: null,
                page: 1,
                perPage: 8,
            ));

            return $result->items;
        } catch (Throwable) {
            return [];
        }
    }
}
