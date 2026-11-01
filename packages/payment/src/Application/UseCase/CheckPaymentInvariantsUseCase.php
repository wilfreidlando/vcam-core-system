<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Ledger\Contracts\AccountBalanceView;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\LedgerTransactionView;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Contracts\PaymentInvariantReport;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\ReconciliationRun;
use Core\Payment\Domain\Model\ReconciliationRunLevel;
use Core\Payment\Domain\Model\ReconciliationRunStatus;
use Core\Payment\Domain\Model\SettlementAccount;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\Domain\Port\ReconciliationRunRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Clock;
use Core\Support\Identifier;
use DateInterval;
use DateTimeImmutable;

/**
 * ADR-0078 §2 — level A: the Core's own financial invariants, checked
 * by the module that owns the data (here `payment`, which already
 * depends on `ledger` and `wallet`).
 *
 * Every pass leaves a trace in the reconciliation register (ADR-0078
 * §5): "on doit pouvoir prouver que le contrôle a tourné et qu'il a
 * passé". The trace holds counters and identifiers only — never
 * anything personal (ADR-0083 §4).
 */
final readonly class CheckPaymentInvariantsUseCase implements CheckPaymentInvariants
{
    /**
     * The idempotency key of a settlement is the payment's id
     * (ADR-0078 §3: « la clé d'idempotence = id du paiement »).
     *
     * A bare UUID therefore designates a payment, and the transaction
     * holding it is that payment's settlement — whichever path booked
     * it: the direct settlement (`payment_settlement`), the unallocated
     * one (`payment_unallocated`), or the wallet credit of a payment
     * (`wallet_credit`, ADR-0076 §3). The other keys are NOT
     * settlements and are excluded: `money_exception:<id>:resolution`,
     * `money_exception:<id>:wallet`, `clearing_reclassement:…`,
     * `adjustment:<id>`, `wallet_transfer:…`, `wallet_withdrawal:…`.
     */
    private const PAYMENT_ID_KEY = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * The internal movements, which are never a payment settlement
     * (ADR-0078 §3: « Transferts, retraits enregistrés, imputations et
     * corrections sont hors périmètre par construction — ce sont des
     * mouvements internes »). Their idempotency key is caller-provided
     * and may look like a UUID, so the type is what tells them apart
     * from a settlement.
     */
    private const INTERNAL_MOVEMENT_TYPES = ['wallet_debit', 'wallet_transfer', 'wallet_withdrawal'];

    private const WALLET_KIND = 'wallet';

    /** Every succeeded payment has its settlement transaction. */
    private const INVARIANT_UNSETTLED_PAYMENT = 'unsettled_payment';

    /** No settlement transaction without a succeeded payment. */
    private const INVARIANT_ORPHAN_SETTLEMENT = 'orphan_settlement';

    /** The trial balance of the project nets to zero, per currency. */
    private const INVARIANT_TRIAL_BALANCE = 'trial_balance';

    /** The wallets never hold more than what was collected. */
    private const INVARIANT_WALLETS_EXCEED_CLEARING = 'wallets_exceed_clearing';

    private const BATCH = 1000;

    public function __construct(
        private PaymentRepository $payments,
        private MoneyExceptionRepository $exceptions,
        private FindPostedLedgerKeys $postedKeys,
        private ListLedgerTransactions $transactions,
        private TrialBalance $trialBalance,
        private ReconciliationRunRepository $runs,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function check(int $windowHours): PaymentInvariantReport
    {
        $now = $this->clock->now();
        $since = $now->sub(new DateInterval('PT'.max(1, $windowHours).'H'));
        $violations = [];
        $opened = 0;
        $runIds = [];

        $succeeded = $this->payments->findSucceededUpdatedSince($since, self::BATCH);
        $byProject = [];
        foreach ($succeeded as $payment) {
            $byProject[$payment->projectId][] = $payment;
        }

        $projectIds = $this->payments->projectsWithActivitySince($since);
        foreach (array_keys($byProject) as $projectId) {
            if (! in_array($projectId, $projectIds, true)) {
                $projectIds[] = $projectId;
            }
        }

        foreach ($projectIds as $projectId) {
            $projectViolations = [];
            $projectOpened = 0;
            $exceptionIds = [];
            $checked = [];
            $skipped = [];

            // 2. Every succeeded payment has its settlement transaction.
            $checked[] = self::INVARIANT_UNSETTLED_PAYMENT;
            $projectPayments = $byProject[$projectId] ?? [];
            if ($projectPayments !== []) {
                $posted = array_flip($this->postedKeys->findPosted(
                    $projectId,
                    array_map(static fn (Payment $p): string => $p->id, $projectPayments),
                ));

                foreach ($projectPayments as $payment) {
                    if (isset($posted[$payment->id])) {
                        continue;
                    }

                    $projectViolations[] = sprintf('paiement %s réussi sans écriture de règlement', $payment->id);
                    $exceptionId = $this->open(
                        projectId: $projectId,
                        check: 'unsettled_payment',
                        key: $payment->id,
                        amount: $payment->amount,
                        currency: $payment->currency,
                        paymentId: $payment->id,
                        summary: sprintf('Le paiement %s est réussi (%d %s) mais n\'a aucune écriture au grand livre.', $payment->idempotencyKey, $payment->amount, $payment->currency),
                    );
                    if ($exceptionId !== null) {
                        $projectOpened++;
                        $exceptionIds[] = $exceptionId;
                    }
                }
            }

            // 3. No settlement transaction without a succeeded payment.
            $checked[] = self::INVARIANT_ORPHAN_SETTLEMENT;
            $cursor = null;
            do {
                $page = $this->transactions->list(new ListLedgerTransactionsCommand(
                    projectId: $projectId,
                    from: $since,
                    // Every type is read: a settlement is recognised by
                    // its idempotency key, not by its type (ADR-0078 §3).
                    types: null,
                    cursor: $cursor,
                    limit: 200,
                ));

                $settlements = $this->settlementsOf($page->items);

                $paymentsById = [];
                foreach ($this->payments->findByIds(array_map(static fn (LedgerTransactionView $t): string => $t->idempotencyKey, $settlements)) as $payment) {
                    $paymentsById[$payment->id] = $payment;
                }

                foreach ($settlements as $transaction) {
                    $payment = $paymentsById[$transaction->idempotencyKey] ?? null;
                    if ($payment === null || $payment->projectId !== $projectId || $payment->status !== PaymentStatus::Succeeded) {
                        $amount = $transaction->lines[0]->amountMinor ?? 0;
                        $currency = $transaction->lines[0]->currency ?? 'XAF';
                        $projectViolations[] = sprintf('écriture de règlement %s sans paiement réussi', $transaction->id);
                        $exceptionId = $this->open(
                            projectId: $projectId,
                            check: 'orphan_settlement',
                            key: $transaction->id,
                            amount: $amount,
                            currency: $currency,
                            paymentId: null,
                            summary: sprintf('L\'écriture %s (%s) n\'a pas de paiement réussi correspondant.', $transaction->id, $transaction->type),
                        );
                        if ($exceptionId !== null) {
                            $projectOpened++;
                            $exceptionIds[] = $exceptionId;
                        }
                    }
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null);

            // 1 and 4, per currency.
            $byCurrency = [];
            foreach ($this->trialBalance->forProject($projectId) as $balance) {
                $byCurrency[$balance->currency][] = $balance;
            }

            if ($byCurrency === []) {
                // Nothing was ever written for this project: there is no
                // currency to check the balance in, and no wallet to
                // compare with the collections. Those invariants are NOT
                // evaluated — and the trace must say so instead of
                // claiming a fixed number of checks.
                $skipped[] = self::INVARIANT_TRIAL_BALANCE;
                $skipped[] = self::INVARIANT_WALLETS_EXCEED_CLEARING;
            } else {
                $checked[] = self::INVARIANT_TRIAL_BALANCE;
                $checked[] = self::INVARIANT_WALLETS_EXCEED_CLEARING;
            }

            foreach ($byCurrency as $currency => $balances) {
                $net = array_sum(array_map(static fn (AccountBalanceView $b): int => $b->netDebitMinor(), $balances));
                if ($net !== 0) {
                    $projectViolations[] = sprintf('balance du projet %s non nulle en %s (%d)', $projectId, $currency, $net);
                    $exceptionId = $this->open(
                        projectId: $projectId,
                        check: 'trial_balance',
                        key: $currency,
                        amount: abs($net),
                        currency: $currency,
                        paymentId: null,
                        summary: sprintf('La balance de vérification du projet n\'est pas nulle en %s : écart de %d.', $currency, $net),
                    );
                    if ($exceptionId !== null) {
                        $projectOpened++;
                        $exceptionIds[] = $exceptionId;
                    }
                }

                $clearing = 0;
                $wallets = 0;
                foreach ($balances as $balance) {
                    if ($balance->kind === SettlementAccount::CLEARING) {
                        $clearing += $balance->netDebitMinor();
                    } elseif ($balance->kind === self::WALLET_KIND) {
                        $wallets += -$balance->netDebitMinor();
                    }
                }
                if ($wallets > $clearing) {
                    $projectViolations[] = sprintf('wallets du projet %s au-dessus de l\'encaissé en %s', $projectId, $currency);
                    $exceptionId = $this->open(
                        projectId: $projectId,
                        check: 'wallets_exceed_clearing',
                        key: $currency,
                        amount: $wallets - $clearing,
                        currency: $currency,
                        paymentId: null,
                        summary: sprintf('Les wallets du projet détiennent %d %s de plus que ce qui a été encaissé.', $wallets - $clearing, $currency),
                    );
                    if ($exceptionId !== null) {
                        $projectOpened++;
                        $exceptionIds[] = $exceptionId;
                    }
                }
            }

            $violations = array_merge($violations, $projectViolations);
            $opened += $projectOpened;

            $runIds[] = $this->recordRun(
                projectId: $projectId,
                violations: $projectViolations,
                exceptionsOpened: $projectOpened,
                exceptionIds: $exceptionIds,
                invariantsChecked: count($checked),
                invariantsSkipped: $skipped,
                windowStart: $since,
                windowEnd: $now,
            );
        }

        return new PaymentInvariantReport(
            projectsChecked: count($projectIds),
            paymentsChecked: count($succeeded),
            exceptionsOpened: $opened,
            violations: $violations,
            runIds: $runIds,
        );
    }

    /**
     * The transactions of a page that ARE settlements — recognised by
     * their idempotency key, the payment's id (ADR-0078 §3), and not by
     * their type: the wallet credit of a payment is a settlement too
     * (ADR-0076 §3).
     *
     * @param list<LedgerTransactionView> $transactions
     * @return list<LedgerTransactionView>
     */
    private function settlementsOf(array $transactions): array
    {
        $settlements = [];

        foreach ($transactions as $transaction) {
            if (in_array($transaction->type, self::INTERNAL_MOVEMENT_TYPES, true)) {
                continue;
            }

            if (preg_match(self::PAYMENT_ID_KEY, $transaction->idempotencyKey) !== 1) {
                continue;
            }

            $settlements[] = $transaction;
        }

        return $settlements;
    }

    /**
     * Opens the critical money exception of a violation — once: an
     * unresolved one is not opened again on the next run.
     *
     * @return string|null the exception's id when it was actually opened
     */
    private function open(string $projectId, string $check, string $key, int $amount, string $currency, ?string $paymentId, string $summary): ?string
    {
        if ($this->exceptions->hasUnresolvedControlFailure($projectId, $check, $key)) {
            return null;
        }

        $id = $this->identifier->generate();

        $created = $this->exceptions->create(MoneyException::open(
            id: $id,
            projectId: $projectId,
            kind: MoneyExceptionKind::InvariantViolation,
            severity: MoneyExceptionSeverity::Critical,
            amount: $amount,
            currency: $currency,
            bookedAmount: 0,
            paymentId: $paymentId,
            subjectRef: null,
            summary: $summary,
            details: ['check' => $check, 'key' => $key],
            now: $this->clock->now(),
        ));

        return $created ? $id : null;
    }

    /**
     * ADR-0078 §5 — the trace of the pass: what was checked, what was
     * found, which exceptions were opened. Counters and identifiers only
     * (ADR-0083 §4).
     *
     * `invariants_checked` counts the invariants this pass REALLY
     * evaluated — a project without a single ledger entry has no
     * currency to check its trial balance in, so those two are reported
     * as skipped rather than counted as checked.
     *
     * @param list<string> $violations
     * @param list<string> $exceptionIds
     * @param list<string> $invariantsSkipped
     */
    private function recordRun(
        string $projectId,
        array $violations,
        int $exceptionsOpened,
        array $exceptionIds,
        int $invariantsChecked,
        array $invariantsSkipped,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): string {
        $run = new ReconciliationRun(
            id: $this->identifier->generate(),
            projectId: $projectId,
            // Level A is not provider-specific (ADR-0078 §5).
            providerCode: null,
            level: ReconciliationRunLevel::Invariants,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            status: $violations === [] ? ReconciliationRunStatus::Completed : ReconciliationRunStatus::Failed,
            stats: [
                'invariants_checked' => $invariantsChecked,
                'invariants_skipped' => $invariantsSkipped,
                'violations' => count($violations),
                'exceptions_opened' => $exceptionsOpened,
                'exception_ids' => $exceptionIds,
            ],
            startedAt: $windowEnd,
            finishedAt: $windowEnd,
        );

        $this->runs->record($run);

        return $run->id;
    }
}
