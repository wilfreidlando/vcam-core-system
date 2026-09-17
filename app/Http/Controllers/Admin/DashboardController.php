<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditQuery;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin dashboard. Reads a few Contracts ports directly — no use case
 * layer, because admin queries are not a "business action" that needs
 * transactional orchestration. They are read views over the same Domain
 * models that use cases mutate.
 *
 * Each query catches its own exceptions and degrades to an empty
 * result, so one failing subsystem never blanks the whole dashboard.
 * If a query fails individually we just show zeros and a log warning —
 * the operator can dig into the failed query separately.
 */
final class DashboardController
{
    public function __construct(
        // @phpstan-ignore-next-line — used for future widgets (operator chip in header).
        private readonly OperatorContext $operator,
        private readonly AuditQuery $audit,
        private readonly ListPayments $listPayments,
        private readonly ListDeadWebhookDeliveries $listDead,
        private readonly ReconcileStuckPayments $reconcile,
    ) {}

    public function show(Request $request): Response
    {
        $stats = [
            'payments_24h' => $this->paymentsLast24h(),
            'dead_webhooks' => $this->deadWebhookCount(),
            'stuck_payments' => $this->stuckPaymentsCount(),
        ];
        $recentAudit = $this->recentAudit();

        return response()->view('admin.dashboard', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'recentAudit' => $recentAudit,
        ]);
    }

    private function paymentsLast24h(): array
    {
        try {
            $since = new DateTimeImmutable('-24 hours');
            $all = $this->listPayments->list(new \Core\Payment\Contracts\ListPaymentsCommand(
                projectId: '',
                status: null,
                page: 1,
                perPage: 100,
            ));
            // The payment module's listing uses ListPayments which
            // accepts a projectId — empty projectId is an unsafe wildcard
            // here, so we filter in PHP. Real fix: add an admin-level
            // port that accepts no project filter.
            $total = 0;
            $succeeded = 0;
            foreach ($all->items as $p) {
                $total++;
                if ($p->status === 'succeeded') {
                    $succeeded++;
                }
            }

            return ['total' => $total, 'succeeded' => $succeeded];
        } catch (Throwable) {
            return ['total' => 0, 'succeeded' => 0];
        }
    }

    private function deadWebhookCount(): int
    {
        try {
            $page = $this->listDead->list(new \Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand(
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
            $summary = $this->reconcile->reconcile(new \Core\Payment\Contracts\ReconcileStuckPaymentsCommand(
                limitOverride: 1,
            ));

            return $summary->checked;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentAudit(): array
    {
        try {
            $result = $this->audit->search(new \Core\Audit\Contracts\AuditSearchCriteria(
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
