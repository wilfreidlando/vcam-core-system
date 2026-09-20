<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ListStuckPayments;
use Core\Payment\Contracts\ListStuckPaymentsCommand;
use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Payment\Contracts\ReconcileStuckPaymentsCommand;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Reconciliation admin: form to trigger a sync run + display the
 * summary. The scheduled job runs every 5 minutes via the platform's
 * cron; this UI exposes the same code path on-demand.
 */
final class ReconciliationController
{
    /**
     * Mirrors TriggerPaymentReconciliationController::ON_DEMAND_BATCH_LIMIT
     * (the JSON API's own on-demand cap) — the form used to advertise a
     * cap of 20 while its validation silently allowed up to 10,000,
     * letting an operator trigger up to 10,000 synchronous live-provider
     * calls in one request (review finding, Lot 6 follow-up).
     */
    private const ON_DEMAND_BATCH_LIMIT = 20;

    public function __construct(
        private readonly ReconcileStuckPayments $reconcile,
        private readonly ListStuckPayments $listStuck,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function new(OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('payments:reconcile:write')) {
            abort(403, 'The payments:reconcile:write permission is required.');
        }

        // No ->withInput() here (review finding, Lot 6 follow-up): that
        // method only exists on RedirectResponse, not the plain view
        // Response response()->view() returns — calling it fatally
        // errored on every GET to this page. The form has no `old()`
        // values to restore on a fresh GET anyway.
        return response()->view('admin.reconciliation.new', [
            'title' => 'Run reconciliation',
            'result' => session('admin.reconciliation.last_result'),
            'previewPayments' => $this->previewStuckPayments(),
        ]);
    }

    public function run(Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('payments:reconcile:write')) {
            abort(403, 'The payments:reconcile:write permission is required.');
        }

        /** @var array{limit_override?: string|int} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'limit_override' => ['nullable', 'integer', 'min:1', 'max:'.self::ON_DEMAND_BATCH_LIMIT],
        ]);
        $limit = isset($data['limit_override']) ? (int) $data['limit_override'] : null;

        try {
            $summary = $this->reconcile->reconcile(new ReconcileStuckPaymentsCommand(
                limitOverride: $limit,
            ));
        } catch (Throwable $e) {
            return back()->withErrors(['limit_override' => 'Reconciliation failed: '.$e->getMessage()]);
        }

        // The JSON API's equivalent controller audits this action; this
        // web controller used to skip it entirely, leaving no audit
        // trail for a reconciliation run triggered from the console
        // (security review finding).
        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'payment_reconciliation.triggered',
            resource: 'payment_reconciliation_run',
            before: null,
            after: ['checked' => $summary->checked, 'updated' => $summary->updated, 'errors' => $summary->errors],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        // Persist the last summary in the session so the redirect lands
        // on the form view with results displayed.
        // @phpstan-ignore-next-line — session()->flash() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->flash(
            'admin.reconciliation.last_result',
            [
                'checked' => $summary->checked,
                'updated' => $summary->updated,
                'errors' => $summary->errors,
            ],
        );

        return redirect()->route('admin.reconciliation.new')
            ->with('status', sprintf(
                'Reconciliation ran: %d checked, %d updated, %d errors.',
                $summary->checked,
                $summary->updated,
                $summary->errors,
            ));
    }

    /**
     * A preview of exactly which payments `run()` would check — shown
     * above the trigger form so an operator never fires reconciliation
     * blind (review finding: reconciliation used to run with no
     * visibility into what it would touch). Same defensive
     * try/catch-degrade pattern as `DashboardController`'s stats: a
     * failed preview lookup shows an empty list rather than erroring the
     * whole page.
     *
     * @return list<\Core\Payment\Contracts\StuckPaymentView>
     */
    private function previewStuckPayments(): array
    {
        try {
            return $this->listStuck->list(new ListStuckPaymentsCommand(self::ON_DEMAND_BATCH_LIMIT));
        } catch (Throwable) {
            return [];
        }
    }
}
