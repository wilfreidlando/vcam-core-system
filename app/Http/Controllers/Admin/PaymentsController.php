<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\GetPaymentStatusCommand;
use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\ListPaymentsCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payments admin: list + detail. Reads the existing `ListPayments`/
 * `GetPaymentStatus` contracts (G3c-compliant — payment admin stays
 * within `Contracts/`).
 *
 * Filters: `project_id` (UUID required), `channel`, `status` (canonical
 * string value, never the enum directly — G3c), `from`, `to`. Unrecognised
 * status / channel are silently ignored (matches the API behaviour).
 */
final class PaymentsController
{
    public function __construct(
        private readonly ListPayments $listPayments,
        private readonly GetPaymentStatus $getPaymentStatus,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('payments:read')) {
            abort(403, 'The payments:read permission is required.');
        }

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

        // A malformed (non-UUID) project_id would otherwise reach a
        // `WHERE project_id = ?` against a Postgres `uuid` column and
        // crash with an uncaught QueryException (review finding, Lot 6
        // follow-up — same class of bug already fixed on the JSON API).
        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        $pageResult = $this->listPayments->list(new ListPaymentsCommand(
            projectId: $projectId,
            status: $status === '' ? null : $status,
            page: $page,
            perPage: $perPage,
        ));

        // Filter by channel client-side (contract doesn't carry a channel
        // filter on list — the ListPaymentsCommand only takes status).
        $items = array_values(array_filter(
            $pageResult->items,
            static fn ($p) => $channel === '' || $p->channel === $channel,
        ));

        return response()->view('admin.payments.index', [
            'title' => 'Payments',
            'payments' => $items,
            'total' => $pageResult->total,
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

    public function show(string $payment, Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('payments:read')) {
            abort(403, 'The payments:read permission is required.');
        }

        $projectId = (string) $request->query('project_id', '');
        if ($projectId === '') {
            return redirect()->route('admin.payments.index')->withErrors([
                'project_id' => 'A project_id is required to look up a payment.',
            ]);
        }

        Validator::make(
            ['payment' => $payment, 'project_id' => $projectId],
            ['payment' => 'uuid', 'project_id' => 'uuid'],
        )->validate();

        try {
            $view = $this->getPaymentStatus->get(new GetPaymentStatusCommand(
                paymentId: $payment,
                requestingProjectId: $projectId,
            ));
        } catch (RuntimeException) {
            return redirect()->route('admin.payments.index', ['project_id' => $projectId])->withErrors([
                'payment' => 'Payment not found for that project.',
            ]);
        }

        return response()->view('admin.payments.show', [
            'title' => 'Payment '.substr($view->id, 0, 8).'…',
            'payment' => $view,
        ]);
    }
}
