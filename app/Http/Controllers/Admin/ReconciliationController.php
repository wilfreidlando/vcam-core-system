<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Payment\Contracts\ReconcileStuckPayments;
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
    public function __construct(
        private readonly ReconcileStuckPayments $reconcile,
    ) {}

    public function new(Request $request): Response
    {
        return response()->view('admin.reconciliation.new', [
            'title' => 'Run reconciliation',
            'result' => session('admin.reconciliation.last_result'),
        ])->withInput($request->old());
    }

    public function run(Request $request): \Illuminate\Http\RedirectResponse
    {
        /** @var array{limit_override?: string|int} $data */
        $data = $request->validate([
            'limit_override' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        /** @var int|null $limit */
        $limit = isset($data['limit_override']) && $data['limit_override'] !== null
            ? (int) $data['limit_override']
            : null;

        try {
            $summary = $this->reconcile->reconcile(new \Core\Payment\Contracts\ReconcileStuckPaymentsCommand(
                limitOverride: $limit,
            ));
        } catch (Throwable $e) {
            return back()->withErrors(['limit_override' => 'Reconciliation failed: '.$e->getMessage()]);
        }

        // Persist the last summary in the session so the redirect lands
        // on the form view with results displayed.
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
}
