<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Console\Commands;

use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerTransactionLine;
use Core\Ledger\Contracts\ListLedgerTransactions;
use Core\Ledger\Contracts\ListLedgerTransactionsCommand;
use Core\Ledger\Contracts\TrialBalance;
use Core\Payment\Domain\Model\ClearingReclassificationOutcome;
use Core\Payment\Domain\Model\SettlementAccount;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Payment\Domain\Port\SettlementLedger;
use DateTimeImmutable;
use Illuminate\Console\Command as ConsoleCommand;

/**
 * ADR-0076 §2 — « Une écriture de reclassement unique, motivée et
 * auditée, solde l'ancien compte vers le nouveau au déploiement. »
 *
 * Before that rule, every settlement debited the project-level `clearing`
 * account (reference `''`). Now a settlement debits the PROVIDER's own
 * account (`clearing:<prestataire>`) — but the money booked before still
 * sits on the historical account. This command moves it over: one
 * balanced transaction per (project, currency, provider), nothing else.
 *
 * It never guesses. A project with several providers cannot have its
 * historical balance attributed to one of them without a human
 * decision, so the command refuses and says so; a project with no
 * provider at all holds no money any provider owns, so there is nothing
 * to move. Either way, the balance is left untouched.
 *
 * And it only moves what it can IDENTIFY as the provider's own money
 * (ADR-0076 §2). A balance that is not entirely explained by
 * identifiable settlements is refused, with the unexplained residue
 * displayed, project by project, currency by currency — a human
 * decision stays necessary.
 *
 * A wallet debit no longer lands on this account — it balances against
 * the project's `withdrawal` account (ADR-0076 §2) — but the money
 * booked BEFORE that rule does: the residue then displayed is that
 * historical share, to be settled by hand.
 *
 * WHY THERE IS NO AUDIT JOURNAL HERE — the `payment` module may not
 * depend on the `audit` module: `ModuleGraph` allows `payment` to reach
 * `support`, `ledger`, `fees`, `identity`, `parties`, `events` and
 * `wallet` only, and a module reaches another one through its
 * `Contracts/` alone (G3c). The probative trace of the reclassification
 * is therefore the LEDGER TRANSACTION ITSELF: it is immutable and
 * chained by fingerprints (ADR-0081 S-16), and it carries the motive,
 * the project, the currency and the amount. That is what makes the
 * write auditable without a second journal.
 *
 * Idempotent: the ledger keys the transaction on
 * `clearing_reclassement:<projectId>:<currency>:<providerCode>`, so
 * running the command again moves nothing a second time — and when the
 * historical account is not empty any more, it says so loudly instead
 * of pretending everything is fine.
 */
final class ReclassifyClearingCommand extends ConsoleCommand
{
    /**
     * The transaction types whose entries on the historical account ARE
     * the provider's money: a settlement of a payment, or a wallet
     * credit. Anything else — a wallet debit, a transfer, a correction
     * — is money the provider never held.
     */
    private const SETTLEMENT_TYPES = ['payment_settlement', 'payment_unallocated', 'wallet_credit'];

    /**
     * The reclassification itself. Its credit on the historical account
     * is money that was ALREADY moved to the provider's account: it
     * must not be reclassified a second time, but it must also not be
     * counted as provider money still waiting here.
     */
    private const RECLASSEMENT_TYPE = 'clearing_reclassement';

    protected $signature = 'payment:reclassify-clearing
        {--project= : Only reclassify this project (uuid)}
        {--reason= : Mandatory motive of the reclassification (ADR-0076 §2)}';

    protected $description = 'Move the historical project-level clearing balance to the provider account (ADR-0076 §2).';

    public function handle(
        PaymentRepository $payments,
        ProviderApplicationRepository $providerApplications,
        TrialBalance $trialBalance,
        ListLedgerTransactions $transactions,
        SettlementLedger $ledger,
    ): int {
        $reason = $this->motive();

        if ($reason === null) {
            $this->error('Le motif du reclassement est obligatoire (--reason) : ADR-0076 §2 exige une écriture de reclassement motivée.');

            return self::FAILURE;
        }

        $projects = $this->projects($payments);

        if ($projects === []) {
            $this->info('Aucun projet à reclasser.');

            return self::SUCCESS;
        }

        $moved = 0;
        $already = 0;
        $refused = 0;
        $failed = false;

        foreach ($projects as $projectId) {
            $balances = $this->historicalBalances($trialBalance, $projectId);

            if ($balances === []) {
                continue;
            }

            $providers = $this->providerCodes($providerApplications, $projectId);

            if (count($providers) !== 1) {
                $refused += count($balances);
                $failed = true;
                $this->refused($projectId, $balances, $providers);

                continue;
            }

            $providerCode = $providers[0];

            foreach ($balances as $currency => $net) {
                // Only the part of the historical account that is
                // identifiable as the provider's own money may move.
                $identifiable = $this->identifiableAmount($transactions, $projectId, $currency);
                $residue = $net - $identifiable;

                if ($residue !== 0) {
                    $refused++;
                    $failed = true;
                    $this->warn(sprintf(
                        'Projet %s — %s : %d %s du compte clearing (projet) ne sont pas des règlements identifiables (%d %s identifiés, %d %s de résidu inexpliqué). Rien n\'est déplacé : une décision humaine est nécessaire.',
                        $projectId,
                        $currency,
                        abs($net),
                        $currency,
                        abs($identifiable),
                        $currency,
                        abs($residue),
                        $currency,
                    ));

                    continue;
                }

                $outcome = $ledger->recordClearingReclassification($projectId, $providerCode, $currency, $net, $reason);

                if ($outcome === ClearingReclassificationOutcome::Moved) {
                    $moved++;
                    $this->info(sprintf(
                        'Projet %s — %s : %d %s transférés de clearing (projet) vers clearing:%s. Motif : %s',
                        $projectId,
                        $currency,
                        abs($net),
                        $currency,
                        $providerCode,
                        $reason,
                    ));
                } elseif ($outcome === ClearingReclassificationOutcome::AlreadyPosted) {
                    $already++;
                    $failed = true;
                    $this->error(sprintf(
                        'Projet %s — %s : reclassement déjà effectué, solde résiduel inexpliqué — la clé d\'idempotence est consommée et le compte clearing (projet) n\'est pas vide (%d %s). Rien n\'est déplacé : une décision humaine est nécessaire.',
                        $projectId,
                        $currency,
                        abs($net),
                        $currency,
                    ));
                } else {
                    $refused++;
                    $failed = true;
                    $this->error(sprintf(
                        'Projet %s — %s : rien n\'a pu être écrit (montant nul ou devise non supportée par le grand livre).',
                        $projectId,
                        $currency,
                    ));
                }
            }
        }

        $this->info(sprintf(
            'Reclassement terminé : %d écriture(s), %d déjà faite(s), %d refusée(s). Motif : %s',
            $moved,
            $already,
            $refused,
            $reason,
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The motive of the reclassification — mandatory, non-empty
     * (ADR-0076 §2).
     */
    private function motive(): ?string
    {
        $option = $this->option('reason');

        if (! is_string($option)) {
            return null;
        }

        $reason = trim($option);

        return $reason === '' ? null : $reason;
    }

    /**
     * The projects to look at: every project that ever had a payment —
     * and only those can hold a historical `clearing` balance, since
     * every settlement is a payment.
     *
     * @return list<string>
     */
    private function projects(PaymentRepository $payments): array
    {
        $project = $this->option('project');
        if (is_string($project) && trim($project) !== '') {
            return [trim($project)];
        }

        return $payments->projectsWithActivitySince(new DateTimeImmutable('1970-01-01T00:00:00+00:00'));
    }

    /**
     * The historical (project-level) `clearing` balances of a project,
     * by currency — the money the reclassification has to move.
     *
     * @return array<string, int>
     */
    private function historicalBalances(TrialBalance $trialBalance, string $projectId): array
    {
        $balances = [];

        foreach ($trialBalance->forProject($projectId) as $balance) {
            if ($balance->kind !== SettlementAccount::CLEARING
                || $balance->reference !== SettlementAccount::PROJECT_LEVEL) {
                continue;
            }

            if ($balance->netDebitMinor() !== 0) {
                $balances[$balance->currency] = $balance->netDebitMinor();
            }
        }

        return $balances;
    }

    /**
     * What the historical account holds that IS the provider's money:
     * the entries of the settlement transactions on that very account
     * and currency, less what a previous reclassification already
     * moved out.
     *
     * Signed like the account's own net debit: positive when the
     * provider holds the money.
     */
    private function identifiableAmount(ListLedgerTransactions $transactions, string $projectId, string $currency): int
    {
        $amount = 0;
        $cursor = null;

        do {
            $page = $transactions->list(new ListLedgerTransactionsCommand(
                projectId: $projectId,
                types: [...self::SETTLEMENT_TYPES, self::RECLASSEMENT_TYPE],
                cursor: $cursor,
                limit: 200,
            ));

            foreach ($page->items as $transaction) {
                foreach ($transaction->lines as $line) {
                    if (! $this->touchesHistoricalClearing($line, $currency)) {
                        continue;
                    }

                    $amount += $line->direction === Direction::Debit->value
                        ? $line->amountMinor
                        : -$line->amountMinor;
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $amount;
    }

    /**
     * Whether this entry touches the historical (project-level)
     * `clearing` account, in this currency.
     */
    private function touchesHistoricalClearing(LedgerTransactionLine $line, string $currency): bool
    {
        return $line->accountKind === SettlementAccount::CLEARING
            && $line->accountReference === SettlementAccount::PROJECT_LEVEL
            && trim($line->currency) === strtoupper($currency);
    }

    /**
     * The providers a project is registered with — one application per
     * (project, provider).
     *
     * @return list<string>
     */
    private function providerCodes(ProviderApplicationRepository $providerApplications, string $projectId): array
    {
        $codes = [];

        foreach ($providerApplications->findAllByProject($projectId) as $application) {
            $codes[$application->providerCode] = true;
        }

        return array_keys($codes);
    }

    /**
     * @param array<string, int> $balances
     * @param list<string> $providers
     */
    private function refused(string $projectId, array $balances, array $providers): void
    {
        $amounts = [];
        foreach ($balances as $currency => $net) {
            $amounts[] = sprintf('%d %s', abs($net), $currency);
        }

        if ($providers === []) {
            $this->warn(sprintf(
                'Projet %s — %s : aucun prestataire enregistré, aucun compte clearing:<prestataire> vers lequel déplacer cet argent. Rien à faire.',
                $projectId,
                implode(', ', $amounts),
            ));

            return;
        }

        $this->warn(sprintf(
            'Projet %s — %s : %d prestataire(s) enregistré(s) (%s). Le solde ne peut pas être attribué à l\'un d\'eux sans décision humaine : rien n\'est déplacé.',
            $projectId,
            implode(', ', $amounts),
            count($providers),
            implode(', ', $providers),
        ));
    }
}
