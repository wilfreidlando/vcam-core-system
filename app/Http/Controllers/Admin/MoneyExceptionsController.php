<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesProjectNames;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ListMoneyExceptions;
use Core\Payment\Contracts\ListMoneyExceptionsCommand;
use Core\Payment\Contracts\MoneyExceptionView;
use Core\Payment\Contracts\ResolveMoneyException;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Domain\Exception\MoneyExceptionNotFound;
use Core\Payment\Domain\Exception\MoneyExceptionTransitionRefused;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-0077 — the money exceptions queue: money the platform could not
 * allocate, and failed financial checks. Reading needs
 * `money-exceptions:read`; settling needs `money-exceptions:write`. Every
 * settling action is audited here — `payment` cannot depend on `audit`
 * (ModuleGraph), the same split as ProviderApplicationsController.
 */
final class MoneyExceptionsController
{
    use ResolvesProjectNames;
    use ResolvesScopedProject;

    private const STATUS_FILTERS = ['open', 'awaiting_approval', 'resolved'];

    public function __construct(
        private readonly ListMoneyExceptions $exceptions,
        private readonly ResolveMoneyException $resolver,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly GetProject $getProject,
        private readonly LoggerInterface $logger,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:read');

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, self::STATUS_FILTERS, true) ? $status : null;
        $pageParam = $request->query('page');
        $page = is_string($pageParam) && ctype_digit($pageParam) ? max(1, (int) $pageParam) : 1;

        $result = $this->exceptions->list(new ListMoneyExceptionsCommand(
            projectIds: null,
            status: $status,
            page: $page,
            perPage: 25,
        ), $scope);

        return response()->view('admin.money-exceptions.index', [
            'title' => "Exceptions d'argent",
            'exceptions' => $result->items,
            'total' => $result->total,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'status' => $status,
            'projectNames' => $this->resolveProjectNames(
                $this->getProject,
                array_map(static fn (MoneyExceptionView $e): string => $e->projectId, $result->items),
                $scope,
            ),
        ]);
    }

    public function show(string $exception, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:read');

        $view = $this->exceptions->find($exception, $scope);
        if ($view === null) {
            abort(404);
        }

        return response()->view('admin.money-exceptions.show', [
            'title' => "Exception d'argent",
            'exception' => $view,
            'projectName' => $this->resolveProjectNames($this->getProject, [$view->projectId], $scope)[$view->projectId] ?? null,
            'canWrite' => $operator->scopeFor('money-exceptions:write')->allowsProject($view->projectId),
            'operatorId' => $operator->operatorId,
        ]);
    }

    public function resolve(string $exception, Request $request, OperatorContext $operator): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:write');

        /** @var array{resolution: string, reference?: string|null, note: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'resolution' => ['required', 'string', 'in:refunded_outside,recognized_as_revenue,no_action'],
            'reference' => ['nullable', 'string', 'max:200'],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            // ADR-0069 §2 — the write port checks the scope itself, on the
            // locked row: an exception of another tenant is "not found".
            $before = $this->exceptions->find($exception, $scope);
            $after = $this->resolver->resolve(new ResolveMoneyExceptionCommand(
                exceptionId: $exception,
                resolution: $data['resolution'],
                operatorId: $operator->operatorId,
                reference: $data['reference'] ?? null,
                note: $data['note'],
            ), $scope);
        } catch (MoneyExceptionNotFound) {
            abort(404);
        } catch (MoneyExceptionTransitionRefused $e) {
            return back()->withInput()->withErrors(['resolution' => $e->getMessage()]);
        }

        $this->record($operator, 'money_exception.resolution_proposed', $before, $after);

        return redirect()->route('admin.money-exceptions.show', $exception)->with('status', $after->status === 'resolved'
            ? 'Exception résolue : '.($after->resolutionLabel ?? '').'.'
            : 'Résolution enregistrée. Elle attend la validation d\'une seconde personne (montant au-delà du seuil).');
    }

    public function approve(string $exception, OperatorContext $operator): RedirectResponse
    {
        return $this->decide($exception, $operator, approve: true);
    }

    public function reject(string $exception, OperatorContext $operator): RedirectResponse
    {
        return $this->decide($exception, $operator, approve: false);
    }

    private function decide(string $exception, OperatorContext $operator, bool $approve): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:write');

        try {
            $before = $this->exceptions->find($exception, $scope);
            $after = $approve
                ? $this->resolver->approve($exception, $operator->operatorId, $scope)
                : $this->resolver->reject($exception, $operator->operatorId, $scope);
        } catch (MoneyExceptionNotFound) {
            abort(404);
        } catch (MoneyExceptionTransitionRefused $e) {
            return back()->withErrors(['resolution' => $e->getMessage()]);
        }

        $this->record($operator, $approve ? 'money_exception.approved' : 'money_exception.rejected', $before, $after);

        return redirect()->route('admin.money-exceptions.show', $exception)->with('status', $approve
            ? 'Résolution validée et écrite au grand livre.'
            : 'Résolution refusée : l\'exception est de nouveau ouverte.');
    }

    /**
     * Runs after the decision and its ledger movement are committed. A
     * failing audit write must not turn a committed decision into a 500
     * (the operator would retry an action already done): it is logged as
     * critical, with everything needed to restore the audit row by hand.
     */
    private function record(OperatorContext $operator, string $action, ?MoneyExceptionView $before, MoneyExceptionView $after): void
    {
        try {
            $this->writeAudit($operator, $action, $before, $after);
        } catch (Throwable $e) {
            $this->logger->critical('money_exception.audit_write_failed', [
                'action' => $action,
                'operator_id' => $operator->operatorId,
                'money_exception_id' => $after->id,
                'status' => $after->status,
                'resolution' => $after->resolution,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function writeAudit(OperatorContext $operator, string $action, ?MoneyExceptionView $before, MoneyExceptionView $after): void
    {
        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: $action,
            resource: 'money_exception:'.$after->id,
            before: $before === null ? null : ['status' => $before->status, 'resolution' => $before->resolution],
            after: [
                'status' => $after->status,
                'resolution' => $after->resolution,
                'reference' => $after->resolutionReference,
                'booked_amount_minor' => $after->bookedAmountMinor,
                'currency' => $after->currency,
            ],
            context: ['project_id' => $after->projectId, 'payment_id' => $after->paymentId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $after->projectId,
        ));
    }
}
