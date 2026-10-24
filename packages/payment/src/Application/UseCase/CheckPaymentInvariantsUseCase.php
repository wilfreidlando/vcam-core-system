<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Ledger\Contracts\AccountBalanceView;
use Core\Ledger\Contracts\FindPostedLedgerKeys;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Contracts\PaymentInvariantReport;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\SettlementAccount;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Clock;
use Core\Support\Identifier;
use DateInterval;

final readonly class CheckPaymentInvariantsUseCase implements CheckPaymentInvariants
{
    /** Settlement transaction types only `payment` books (ADR-0076 §3). */
    private const SETTLEMENT_TYPES = ['payment_settlement', 'payment_unallocated'];

    private const WALLET_KIND = 'wallet';

    private const BATCH = 1000;

    public function __construct(
        private PaymentRepository $payments,
        private MoneyExceptionRepository $exceptions,
        private FindPostedLedgerKeys $postedKeys,
        private ListLedgerTransactions $transactions,
        private TrialBalance $trialBalance,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function check(int $windowHours): PaymentInvariantReport
    {
        $now = $this->clock->now();
        $since = $now->sub(new DateInterval('PT'.max(1, $windowHours).'H'));
        $violations = [];
        $opened = 0;

        // 2. Every succeeded payment has its settlement transaction.
        $succeeded = $this->payments->findSucceededUpdatedSince($since, self::BATCH);
        $byProject = [];
        foreach ($succeeded as $payment) {
            $byProject[$payment->projectId][] = $payment;
        }
        foreach ($byProject as $projectId => $projectPayments) {
            $posted = array_flip($this->postedKeys->findPosted($projectId, array_map(static fn (Payment $p): string => $p->id, $projectPayments)));
            foreach ($projectPayments as $payment) {
                if (! isset($posted[$payment->id])) {
                    $violations[] = sprintf('paiement %s réussi sans écriture de règlement', $payment->id);
                    $opened += $this->open(
                        projectId: $projectId,
                        check: 'unsettled_payment',
                        key: $payment->id,
                        amount: $payment->amount,
                        currency: $payment->currency,
                        paymentId: $payment->id,
                        summary: sprintf('Le paiement %s est réussi (%d %s) mais n\'a aucune écriture au grand livre.', $payment->idempotencyKey, $payment->amount, $payment->currency),
                    );
                }
            }
        }

        $projects = $this->payments->projectsWithActivitySince($since);

        foreach ($projects as $projectId) {
            // 3. No settlement transaction without a succeeded payment.
            $cursor = null;
            do {
                $page = $this->transactions->list(new ListLedgerTransactionsCommand(
                    projectId: $projectId,
                    from: $since,
                    types: self::SETTLEMENT_TYPES,
                    cursor: $cursor,
                    limit: 200,
                ));
                $paymentsById = [];
                foreach ($this->payments->findByIds(array_map(static fn ($t): string => $t->idempotencyKey, $page->items)) as $payment) {
                    $paymentsById[$payment->id] = $payment;
                }
                foreach ($page->items as $transaction) {
                    $payment = $paymentsById[$transaction->idempotencyKey] ?? null;
                    if ($payment === null || $payment->projectId !== $projectId || $payment->status !== PaymentStatus::Succeeded) {
                        $amount = $transaction->lines[0]->amountMinor ?? 0;
                        $currency = $transaction->lines[0]->currency ?? 'XAF';
                        $violations[] = sprintf('écriture de règlement %s sans paiement réussi', $transaction->id);
                        $opened += $this->open(
                            projectId: $projectId,
                            check: 'orphan_settlement',
                            key: $transaction->id,
                            amount: $amount,
                            currency: $currency,
                            paymentId: null,
                            summary: sprintf('L\'écriture %s (%s) n\'a pas de paiement réussi correspondant.', $transaction->id, $transaction->type),
                        );
                    }
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);

            // 1 and 4, per currency.
            $byCurrency = [];
            foreach ($this->trialBalance->forProject($projectId) as $balance) {
                $byCurrency[$balance->currency][] = $balance;
            }
            foreach ($byCurrency as $currency => $balances) {
                $net = array_sum(array_map(static fn (AccountBalanceView $b): int => $b->netDebitMinor(), $balances));
                if ($net !== 0) {
                    $violations[] = sprintf('balance du projet %s non nulle en %s (%d)', $projectId, $currency, $net);
                    $opened += $this->open(
                        projectId: $projectId,
                        check: 'trial_balance',
                        key: $currency,
                        amount: abs($net),
                        currency: $currency,
                        paymentId: null,
                        summary: sprintf('La balance de vérification du projet n\'est pas nulle en %s : écart de %d.', $currency, $net),
                    );
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
                    $violations[] = sprintf('wallets du projet %s au-dessus de l\'encaissé en %s', $projectId, $currency);
                    $opened += $this->open(
                        projectId: $projectId,
                        check: 'wallets_exceed_clearing',
                        key: $currency,
                        amount: $wallets - $clearing,
                        currency: $currency,
                        paymentId: null,
                        summary: sprintf('Les wallets du projet détiennent %d %s de plus que ce qui a été encaissé.', $wallets - $clearing, $currency),
                    );
                }
            }
        }

        return new PaymentInvariantReport(
            projectsChecked: count($projects),
            paymentsChecked: count($succeeded),
            exceptionsOpened: $opened,
            violations: $violations,
        );
    }

    private function open(string $projectId, string $check, string $key, int $amount, string $currency, ?string $paymentId, string $summary): int
    {
        if ($this->exceptions->hasUnresolvedControlFailure($projectId, $check, $key)) {
            return 0;
        }

        return $this->exceptions->create(MoneyException::open(
            id: $this->identifier->generate(),
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
        )) ? 1 : 0;
    }
}
