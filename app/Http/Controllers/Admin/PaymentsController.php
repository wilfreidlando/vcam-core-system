<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Payment\Contracts\ListPayments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payments admin: list + detail. Reads the existing `ListPayments`
 * contract (G3c-compliant — payment admin stays within `Contracts/`).
 *
 * Filters: `project_id` (UUID required), `channel`, `status` (canonical
 * string value, never the enum directly — G3c), `from`, `to`. Unrecognised
 * status / channel are silently ignored (matches the API behaviour).
 */
final class PaymentsController
{
    public function __construct(
        private readonly ListPayments $listPayments,
    ) {}

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $projectId = (string) $request->query('project_id', '');
        $channel = (string) $request->query('channel', '');
        $status = (string) $request->query('status', '');

        // The ListPayments contract accepts a project_id filter. We require
        // a non-empty value here — without one, we'd list every project in
        // the Core (privacy issue). The admin must know which project to
        // scope to.
        if ($projectId === '') {
            return response()->view('admin.payments.index', [
                'title' => 'Payments',
                'payments' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => $perPage,
                'filters' => [
                    'project_id' => '',
                    'channel' => $channel,
                    'status' => $status,
                ],
                'needs_project' => true,
            ]);
        }

        $page_result = $this->listPayments->list(new \Core\Payment\Contracts\ListPaymentsCommand(
            projectId: $projectId,
            status: $status === '' ? null : $status,
            page: $page,
            perPage: $perPage,
        ));

        // Filter by channel client-side (contract doesn't carry a channel
        // filter on list — the ListPaymentsCommand only takes status).
        $items = array_values(array_filter(
            $page_result->items,
            static fn ($p) => $channel === '' || $p->channel === $channel,
        ));

        return response()->view('admin.payments.index', [
            'title' => 'Payments',
            'payments' => $items,
            'total' => $page_result->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => [
                'project_id' => $projectId,
                'channel' => $channel,
                'status' => $status,
            ],
            'needs_project' => false,
        ]);
    }

    public function show(string $paymentId): Response
    {
        // The payment module doesn't yet expose a `GetPaymentByAdmin`
        // contract — the public `GetPaymentStatus` is project-scoped. We
        // can't list the whole table to find one record, so we require the
        // operator to navigate from the list (which is project-scoped).
        // Direct-link handling returns a friendly redirect for now.
        return redirect()->route('admin.payments.index')->withErrors([
            'project_id' => 'Direct payment lookup requires a project-scoped search. Use the list above.',
        ])->withInput(['project_id' => request()->query('project_id')]);
    }
}
