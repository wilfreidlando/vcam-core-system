<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesProjectNames;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Ledger\Contracts\LedgerAdjustments;
use Core\Ledger\Contracts\LedgerAdjustmentView;
use Core\Ledger\Contracts\LedgerTransactionView;
use Core\Ledger\Contracts\ListLedgerAdjustments;
use Core\Ledger\Contracts\ListLedgerAdjustmentsCommand;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\ProposeLedgerAdjustmentCommand;
use Core\Ledger\Domain\Exception\LedgerAdjustmentNotFound;
use Core\Ledger\Domain\Exception\LedgerAdjustmentReversalRefused;
use Core\Ledger\Domain\Exception\LedgerAdjustmentTransitionRefused;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Http\ProblemDetails;
use Core\Support\UnitOfWork;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-0076 §6 — the operating corrections of the ledger: an amount, two
 * accounts, a motive, and a DOUBLE VALIDATION (a second console account
 * — `org_finance` or the platform — approves).
 *
 * Reading needs `money-exceptions:read`, deciding needs
 * `money-exceptions:write`: the same permissions as the money exceptions
 * queue, its closest sibling — the two are the two halves of the same
 * question ("this money is not where it should be, what do we do?"), and
 * both are reserved to the finance role and to the platform. A separate
 * `ledger:adjust` permission would have meant touching `identity`'s
 * role enums; reusing the existing ones keeps the audience exactly the
 * one ADR-0076 §6 names, without widening anyone's rights.
 *
 * Every deciding action is audited here — `ledger` cannot depend on
 * `audit` (G2), the same split as MoneyExceptionsController.
 */
final class LedgerAdjustmentsController
{
    use ResolvesProjectNames;
    use ResolvesScopedProject;

    /**
     * The statuses a correction goes through.
     */
    private const STATUSES = ['proposed', 'approved', 'rejected'];

    /**
     * The chart of accounts of ADR-0076 §2, as the console offers it.
     * The kind stays an open registry on the ledger's side (ADR-0033
     * §4): the list below is what the console proposes, not what the
     * ledger accepts.
     *
     * `wallet` is NOT part of it: the balance of a party is the `wallet`
     * module's, and it is the only module allowed to update it. A
     * correction writes only to the ledger, so it would leave the two
     * apart — the domain refuses it too (`LedgerAdjustment::propose()`).
     */
    private const ACCOUNT_KINDS = [
        'adjustment' => 'Compte de contrepartie des corrections',
        'bad_debt' => 'Créances abandonnées',
        'clearing' => 'Compte de bascule du prestataire',
        'collections' => 'Encaissements en attente d\'imputation',
        'customer_receivable' => 'Créance client',
        'manual_receipts' => 'Encaissements hors ligne',
        'payment_exceptions' => 'Encaissements non imputables',
        'payout_in_transit' => 'Versements en cours',
        'provider_fees' => 'Frais du prestataire',
        'revenue' => 'Revenu du projet',
        'withdrawal' => 'Sorties enregistrées',
    ];

    /**
     * The kind of the account of a party — the one account kind a
     * correction may never touch.
     */
    private const WALLET_KIND = 'wallet';

    /**
     * The type of the transaction a correction produces — and the type a
     * correction may never counter-pass.
     */
    private const ADJUSTMENT_TYPE = 'adjustment';

    private const CURRENCIES = ['XAF', 'USD'];

    public function __construct(
        private readonly ListLedgerAdjustments $adjustments,
        private readonly LedgerAdjustments $ledgerAdjustments,
        private readonly ListLedgerTransactions $transactions,
        private readonly ListProjects $projects,
        private readonly GetProject $getProject,
        private readonly IsSupportedCurrency $isSupportedCurrency,
        private readonly AuditLogger $audit,
        private readonly UnitOfWork $unitOfWork,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:read');

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, self::STATUSES, true) ? $status : null;

        $pageParam = $request->query('page');
        $page = is_string($pageParam) && ctype_digit($pageParam) ? max(1, (int) $pageParam) : 1;

        $result = $this->adjustments->list(new ListLedgerAdjustmentsCommand(
            projectIds: null,
            status: $status,
            page: $page,
            perPage: 25,
        ), $scope);

        return response()->view('admin.ledger-adjustments.index', [
            'title' => "Corrections d'exploitation",
            'adjustments' => $result->items,
            'total' => $result->total,
            'page' => $result->page,
            'perPage' => $result->perPage,
            'status' => $status,
            'projectNames' => $this->resolveProjectNames(
                $this->getProject,
                array_map(static fn (LedgerAdjustmentView $adjustment): string => $adjustment->projectId, $result->items),
                $scope,
            ),
            // ADR-0069 — the page never offers an action the account
            // cannot take: a read-only auditor would land on a 403.
            'canWrite' => $operator->hasPermissionAnywhere('money-exceptions:write'),
        ]);
    }

    public function create(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:write');

        $projects = $this->projects->list(new ListProjectsCommand(perPage: 100), $scope);

        // ADR-0076 §5 — a correction names the transaction it reverses,
        // so the operator picks one: the transactions of the project come
        // first, then the correction's own accounts.
        $projectId = $request->query('project_id');
        $projectId = is_string($projectId) && $projectId !== '' ? $projectId : null;

        $transactions = [];
        if ($projectId !== null) {
            $projectId = $this->resolveScopedProject($this->getProject, $operator, $projectId, 'money-exceptions:write');
            $transactions = $this->reversibleTransactions($projectId, $scope);
        }

        return response()->view('admin.ledger-adjustments.create', [
            'title' => "Nouvelle correction d'exploitation",
            'projects' => $projects->items,
            'accountKinds' => self::ACCOUNT_KINDS,
            'currencies' => self::CURRENCIES,
            'selectedProjectId' => $projectId,
            'transactions' => $transactions,
        ]);
    }

    public function store(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:write');

        /** @var array{project_id: string, source_kind: string, source_reference?: string|null, destination_kind: string, destination_reference?: string|null, reverses_transaction_id?: string|null, reverses_transaction_id_manual?: string|null, amount_minor: string|int, currency: string, reason: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'source_kind' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'source_reference' => ['nullable', 'string', 'max:200'],
            'destination_kind' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'destination_reference' => ['nullable', 'string', 'max:200'],
            // ADR-0076 §5 — the transaction the correction counter-passes.
            // The list proposes the most recent ones; the free field
            // reaches the older ones. One of the two names it.
            'reverses_transaction_id' => ['nullable', 'uuid'],
            'reverses_transaction_id_manual' => ['nullable', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        // ADR-0069 §2 — the permission is not the scope: the project must
        // also be one the account may act on (404 otherwise).
        $projectId = $this->resolveScopedProject($this->getProject, $operator, $data['project_id'], 'money-exceptions:write');

        $currency = strtoupper($data['currency']);
        if (! $this->isSupportedCurrency->isSupported($currency)) {
            return $this->refused($request, 'currency', 'Devise non prise en charge par le grand livre.');
        }

        $sourceReference = trim($data['source_reference'] ?? '');
        $destinationReference = trim($data['destination_reference'] ?? '');

        // ADR-0076 §5 — the transaction the correction counter-passes.
        // The free field wins when it carries something: it is the
        // explicit choice, and it is the only way to reach an entry
        // older than the ones the list proposes.
        $reversesTransactionId = trim($data['reverses_transaction_id_manual'] ?? '');
        if ($reversesTransactionId === '') {
            $reversesTransactionId = trim($data['reverses_transaction_id'] ?? '');
        }

        if ($reversesTransactionId === '') {
            return $this->refused(
                $request,
                'reverses_transaction_id',
                'La correction doit nommer la transaction qu\'elle contre-passe : choisissez-la dans la liste, ou saisissez son identifiant.',
            );
        }

        // The balance of a party belongs to the `wallet` module, which is
        // the only one allowed to update it. The domain refuses it too —
        // these two checks only say it on the right field of the form.
        if ($data['source_kind'] === self::WALLET_KIND) {
            return $this->refused($request, 'source_kind', $this->walletAccountRefusal());
        }

        if ($data['destination_kind'] === self::WALLET_KIND) {
            return $this->refused($request, 'destination_kind', $this->walletAccountRefusal());
        }

        // A correction moves money between two DIFFERENT accounts: the
        // same account on both sides would book a debit and a credit on
        // it, and no balance would move.
        if ($data['source_kind'] === $data['destination_kind'] && $sourceReference === $destinationReference) {
            return $this->refused($request, 'destination_kind', 'La correction doit porter sur deux comptes différents.');
        }

        try {
            // The correction and the trace of its proposal commit
            // together: a correction nobody can prove is not a correction
            // (ADR-0076 §6).
            $adjustment = $this->unitOfWork->transactional(function () use ($data, $projectId, $currency, $sourceReference, $destinationReference, $reversesTransactionId, $operator, $scope): LedgerAdjustmentView {
                $proposed = $this->ledgerAdjustments->propose(new ProposeLedgerAdjustmentCommand(
                    projectId: $projectId,
                    sourceKind: $data['source_kind'],
                    sourceReference: $sourceReference,
                    destinationKind: $data['destination_kind'],
                    destinationReference: $destinationReference,
                    amountMinor: (int) $data['amount_minor'],
                    currency: $currency,
                    reason: $data['reason'],
                    proposedBy: $operator->operatorId,
                    reversesTransactionId: $reversesTransactionId,
                ), $scope);

                // The trace of the proposal comes BEFORE the movement of
                // money it may trigger: a correction is never written to
                // the ledger before it is proven (ADR-0076 §6).
                $this->record($operator, 'ledger_adjustment.proposed', null, $proposed);

                // Below the double-validation threshold, the correction
                // is booked at once, by the person who proposed it; at
                // or above it, it waits for a second pair of eyes
                // (ADR-0081 S-15) and nothing moves here.
                $booked = $this->ledgerAdjustments->bookWithoutApproval($proposed->id, $scope);

                if ($booked->status !== $proposed->status) {
                    // The money moved without a second pair of eyes: that
                    // movement leaves a trace of its own.
                    $this->record($operator, 'ledger_adjustment.booked_without_approval', $proposed, $booked);
                }

                return $booked;
            });
        } catch (LedgerAdjustmentNotFound) {
            abort(404);
        } catch (LedgerAdjustmentReversalRefused $e) {
            return $this->refused($request, 'reverses_transaction_id', $e->getMessage());
        } catch (LedgerAdjustmentTransitionRefused $e) {
            return $this->refused($request, 'reason', $e->getMessage());
        }

        return redirect()->route('admin.ledger-adjustments.show', $adjustment->id)
            ->with('status', $adjustment->status === 'approved'
                ? 'Correction proposée et écrite au grand livre : elle était sous le seuil de double validation.'
                : 'Correction proposée : elle attend la validation d\'une seconde personne.');
    }

    public function show(string $adjustment, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:read');

        $view = $this->adjustments->find($adjustment, $scope);
        if ($view === null) {
            abort(404);
        }

        return response()->view('admin.ledger-adjustments.show', [
            'title' => "Correction d'exploitation",
            'adjustment' => $view,
            'projectName' => $this->resolveProjectNames($this->getProject, [$view->projectId], $scope)[$view->projectId] ?? null,
            'canWrite' => $operator->scopeFor('money-exceptions:write')->allowsProject($view->projectId),
            'operatorId' => $operator->operatorId,
        ]);
    }

    public function approve(string $adjustment, Request $request, OperatorContext $operator): Response
    {
        return $this->decide($adjustment, $request, $operator, approve: true);
    }

    public function reject(string $adjustment, Request $request, OperatorContext $operator): Response
    {
        return $this->decide($adjustment, $request, $operator, approve: false);
    }

    private function decide(string $adjustment, Request $request, OperatorContext $operator, bool $approve): Response
    {
        $scope = $this->permittedScope($operator, 'money-exceptions:write');

        try {
            // The decision, the money it moves and the trace of the
            // decision commit together: a decision nobody can prove is
            // not a decision (ADR-0076 §6). If the trace cannot be
            // written, the money does not move either, and the operator
            // is told — instead of reading "Correction validée" over a
            // movement nothing remembers.
            $this->unitOfWork->transactional(function () use ($adjustment, $operator, $approve, $scope): void {
                $before = $this->adjustments->find($adjustment, $scope);
                $after = $approve
                    ? $this->ledgerAdjustments->approve($adjustment, $operator->operatorId, $scope)
                    : $this->ledgerAdjustments->reject($adjustment, $operator->operatorId, $scope);

                $this->record($operator, $approve ? 'ledger_adjustment.approved' : 'ledger_adjustment.rejected', $before, $after);
            });
        } catch (LedgerAdjustmentNotFound) {
            abort(404);
        } catch (LedgerAdjustmentTransitionRefused $e) {
            return $this->refused($request, 'adjustment', $e->getMessage());
        } catch (LedgerAdjustmentReversalRefused $e) {
            // The correction breaks an invariant of its own state (a
            // wallet account, the same account on both sides, no
            // transaction to counter-pass): it never reaches the ledger.
            return $this->refused($request, 'adjustment', $e->getMessage());
        }

        return redirect()->route('admin.ledger-adjustments.show', $adjustment)->with('status', $approve
            ? 'Correction validée : elle est écrite au grand livre.'
            : 'Correction refusée : rien n\'a été écrit au grand livre.');
    }

    /**
     * A refusal: the form goes back with its errors, a JSON caller gets
     * RFC 7807.
     */
    private function refused(Request $request, string $field, string $message): Response
    {
        if ($request->expectsJson()) {
            return ProblemDetails::make('ledger-adjustment-refused', 'Correction refusée', 422, $message);
        }

        return back()->withInput()->withErrors([$field => $message]);
    }

    /**
     * The transactions a correction may counter-pass: the project's own
     * transactions, minus the corrections themselves (a correction never
     * corrects another correction).
     *
     * @return list<LedgerTransactionView>
     */
    private function reversibleTransactions(string $projectId, AccessScope $scope): array
    {
        $page = $this->transactions->listScoped(new ListLedgerTransactionsCommand(
            projectId: $projectId,
            limit: 100,
        ), $scope);

        return array_values(array_filter(
            $page->items,
            static fn (LedgerTransactionView $transaction): bool => $transaction->type !== self::ADJUSTMENT_TYPE,
        ));
    }

    /**
     * Why a correction may not touch the account of a party.
     */
    private function walletAccountRefusal(): string
    {
        return 'Une correction d\'exploitation ne peut pas porter sur le compte d\'une partie (wallet) : '
            .'le solde d\'une partie n\'est mis à jour que par le module wallet.';
    }

    /**
     * The trace of a correction — the same shape for the proposal and
     * for the decision, so the two lines can be read together: what was
     * proposed, and what was decided about it.
     *
     * @return array<string, mixed>
     */
    private function trace(LedgerAdjustmentView $adjustment): array
    {
        return [
            'status' => $adjustment->status,
            'amount_minor' => $adjustment->amountMinor,
            'currency' => $adjustment->currency,
            'source_account' => $adjustment->sourceKind.($adjustment->sourceReference === '' ? '' : ':'.$adjustment->sourceReference),
            'destination_account' => $adjustment->destinationKind.($adjustment->destinationReference === '' ? '' : ':'.$adjustment->destinationReference),
            'reversed_transaction_id' => $adjustment->reversesTransactionId,
            'reason' => $adjustment->reason,
            'ledger_transaction_id' => $adjustment->ledgerTransactionId,
        ];
    }

    /**
     * Records the trace of a correction.
     *
     * A failing audit write is NOT swallowed: it is logged as critical,
     * with everything needed to restore the row by hand, and it is
     * raised. The caller runs this inside the transaction that carries
     * the correction itself, so a missing trace rolls the correction
     * back instead of leaving a decision nobody can prove (ADR-0076 §6).
     */
    private function record(OperatorContext $operator, string $action, ?LedgerAdjustmentView $before, LedgerAdjustmentView $after): void
    {
        try {
            $this->audit->record(new AuditEntry(
                actor: 'operator:'.$operator->operatorId,
                action: $action,
                resource: 'ledger_adjustment:'.$after->id,
                before: $before === null ? null : ['status' => $before->status],
                after: $this->trace($after),
                context: ['project_id' => $after->projectId],
                correlationId: null,
                occurredAt: $this->clock->now(),
                projectId: $after->projectId,
            ));
        } catch (Throwable $e) {
            $this->logger->critical('ledger_adjustment.audit_write_failed', [
                'action' => $action,
                'operator_id' => $operator->operatorId,
                'ledger_adjustment_id' => $after->id,
                'status' => $after->status,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
