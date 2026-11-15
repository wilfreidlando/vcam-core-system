<?php

declare(strict_types=1);

namespace App\Http\View;

use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
use Core\Ledger\Contracts\AccountView;
use Core\Payment\Contracts\ListProviderApplications;
use Core\Payment\Contracts\ListProviderApplicationsCommand;
use Core\Support\Access\AccessScope;
use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletsCommand;

/**
 * The console's words for the ledger's accounts (ADR-0125 §6).
 *
 * The ledger writes natures — `wallet`, `clearing`, `customer_receivable`,
 * `revenue`… — because a nature is an open vocabulary the modules use
 * (ADR-0033 §4). The operator never reads them: `customer_receivable` is
 * not a language, and a raw identifier (a wallet id, a customer id) is
 * not a name either.
 *
 * So the console says three things about an account:
 *
 *  - **what it is** — the French name of its nature;
 *  - **whom it belongs to** — the money of the project, or the money of a
 *    client (ADR-0125 §6: « l'argent du projet n'est jamais dans un
 *    `wallet` ») ;
 *  - **what its reference points to** — the client's reference, the
 *    provider's name, the method of an offline receipt — and, when it
 *    points to nothing any more, that it points to nothing: « Portefeuille
 *    inconnu », never an identifier.
 */
final class LedgerAccountLabels
{
    /** The title of the accounts screen. */
    public const PAGE_TITLE = 'Comptes du projet';

    /** The heading of the table of the project's accounts. */
    public const ACCOUNTS_HEADING = 'Les comptes du projet';

    /** The heading of the movements of one account. */
    public const MOVEMENTS_HEADING = 'Les mouvements du compte';

    /** The link that opens the accounts and their movements. */
    public const OPEN_MOVEMENTS = 'Ouvrir les comptes et leurs mouvements';

    /** The link back to the accounting page. */
    public const BACK_TO_ACCOUNTING = 'Retour à la comptabilité';

    /** The balance of the account whose movements are open. */
    public const ACCOUNT_BALANCE = 'Solde du compte : ';

    /** The empty state of an account that never moved. */
    public const NO_MOVEMENT = "Ce compte n'a encore jamais bougé.";

    /** The empty state of a project without any account. */
    public const NO_ACCOUNT = 'Aucun compte pour ce projet.';

    /** The heading of the choice, when an account exists in several currencies. */
    public const MULTIPLE_CURRENCIES = 'Ce compte existe en plusieurs devises';

    /** The columns of the table of the accounts. */
    public const ACCOUNTS_HEADERS = ['Compte', 'Référence expliquée', 'Devise', 'Débit', 'Crédit', 'Solde'];

    /** The columns of the table of the movements. */
    public const MOVEMENTS_HEADERS = ['Date', 'Opération', 'Sens', 'Montant', "Solde après l'écriture"];

    /** How many wallets or customers are read per page. */
    private const PAGE_SIZE = 100;

    /** How many pages are read at most — a console page, not a data dump. */
    private const MAX_PAGES = 20;

    /**
     * wallet id => the external reference of its holder, per project.
     *
     * @var array<string, array<string, string|null>>
     */
    private array $walletHolders = [];

    /**
     * customer id => the reference the project knows it by, per project.
     *
     * @var array<string, array<string, string>>
     */
    private array $customerReferences = [];

    /**
     * provider code => the name the operator gave it, per project.
     *
     * @var array<string, array<string, string>>
     */
    private array $providerNames = [];

    public function __construct(
        private readonly ListWallets $wallets,
        private readonly ListCustomers $customers,
        private readonly ListProviderApplications $providerApplications,
    ) {}

    /**
     * The name of a nature, in French.
     *
     * A settlement account with **no** reference is the project's own
     * account, from before the settlement accounts were split per
     * provider (ADR-0076 §2): it is never presented as a provider's
     * account.
     *
     * An unknown nature is « Compte technique »: the nature is open, so
     * the Core may create one the console has no word for yet — hiding it
     * would hide the problem too.
     */
    public static function nature(string $kind, string $reference = ''): string
    {
        return match ($kind) {
            'wallet' => 'Portefeuille client',
            'clearing' => $reference === '' ? 'Encaissements — compte du projet' : 'Encaissements — prestataire',
            'withdrawal' => 'Sorties de trésorerie',
            'customer_receivable' => 'Créances clients',
            'revenue' => 'Recettes',
            'collections' => 'Encaissements',
            'bad_debt' => 'Créances irrécouvrables',
            'manual_receipts' => 'Reçus hors ligne',
            'payment_exceptions' => 'Exceptions de paiement',
            default => 'Compte technique',
        };
    }

    /**
     * Whose money the account holds.
     *
     * A `wallet` is always a client's wallet, and a receivable is always
     * what a client owes: everything else belongs to the project
     * (ADR-0125 §6).
     */
    public static function owner(string $kind): string
    {
        return match ($kind) {
            'wallet' => "l'argent du client",
            'customer_receivable' => "l'argent dû par le client",
            default => "l'argent du projet",
        };
    }

    /**
     * The rows of the accounts table: the name of the nature, the money's
     * owner, and what the reference points to.
     *
     * @param list<AccountView> $accounts
     * @return list<array{
     *     kind: string,
     *     reference: string,
     *     currency: string,
     *     nature: string,
     *     owner: string,
     *     explanation: string,
     *     debitMinor: int,
     *     creditMinor: int,
     *     netMinor: int
     * }>
     */
    public function rows(array $accounts, string $projectId, AccessScope $scope): array
    {
        $rows = [];

        foreach ($accounts as $account) {
            $rows[] = [
                'kind' => $account->kind,
                'reference' => $account->reference,
                'currency' => $account->currency,
                'nature' => self::nature($account->kind, $account->reference),
                'owner' => self::owner($account->kind),
                'explanation' => $this->explanation($account->kind, $account->reference, $projectId, $scope),
                'debitMinor' => $account->debitMinor,
                'creditMinor' => $account->creditMinor,
                'netMinor' => $account->netDebitMinor(),
            ];
        }

        return $rows;
    }

    /**
     * What the reference of an account points to, said in French.
     *
     * A reference that no longer resolves — a deleted wallet, a customer
     * that was erased — is said as it is (« Portefeuille inconnu »), never
     * as a raw identifier (ADR-0125 §6).
     */
    public function explanation(string $kind, string $reference, string $projectId, AccessScope $scope): string
    {
        return match ($kind) {
            'wallet' => $this->walletHolder($reference, $projectId, $scope),
            'customer_receivable' => $this->customerReference($reference, $projectId, $scope),
            'clearing' => $this->providerName($reference, $projectId, $scope),
            'manual_receipts' => self::receiptMethod($reference),
            default => '—',
        };
    }

    /**
     * The holder of a wallet, as the project knows it.
     */
    private function walletHolder(string $walletId, string $projectId, AccessScope $scope): string
    {
        $holder = $this->walletHolders($projectId, $scope)[$walletId] ?? null;

        return $holder === null || $holder === '' ? 'Portefeuille inconnu' : $holder;
    }

    /**
     * The reference of a customer, as the project knows it.
     */
    private function customerReference(string $customerId, string $projectId, AccessScope $scope): string
    {
        $reference = $this->customerReferences($projectId, $scope)[$customerId] ?? null;

        return $reference === null || $reference === '' ? 'Client inconnu' : $reference;
    }

    /**
     * The name the operator gave to the provider the money is held at.
     *
     * The account carries the provider's code; when the operator named
     * its application, the name is what the operator recognizes — the
     * code is the fallback, never a guess.
     */
    private function providerName(string $providerCode, string $projectId, AccessScope $scope): string
    {
        if ($providerCode === '') {
            return '—';
        }

        return $this->providerNames($projectId, $scope)[$providerCode] ?? $providerCode;
    }

    /**
     * The method an offline receipt was recorded with.
     *
     * An unknown method is returned as it is: a method the Core does not
     * know yet must be given a word before it is shown.
     */
    private static function receiptMethod(string $method): string
    {
        return match ($method) {
            'bank_transfer' => 'virement bancaire',
            'cash' => 'espèces',
            'cheque' => 'chèque',
            'other' => 'autre',
            default => $method === '' ? '—' : $method,
        };
    }

    /**
     * The wallets of the project, and the holder of each.
     *
     * @return array<string, string|null> wallet id => holder's reference
     */
    private function walletHolders(string $projectId, AccessScope $scope): array
    {
        if (isset($this->walletHolders[$projectId])) {
            return $this->walletHolders[$projectId];
        }

        $holders = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $result = $this->wallets->list(new ListWalletsCommand(
                projectId: $projectId,
                page: $page,
                perPage: self::PAGE_SIZE,
            ), $scope);

            foreach ($result->items as $wallet) {
                $holders[$wallet->id] = $wallet->partyExternalRef;
            }

            if (count($result->items) < self::PAGE_SIZE) {
                break;
            }

            $page++;
        }

        $this->walletHolders[$projectId] = $holders;

        return $holders;
    }

    /**
     * The customers of the project, and the reference of each.
     *
     * @return array<string, string> customer id => external reference
     */
    private function customerReferences(string $projectId, AccessScope $scope): array
    {
        if (isset($this->customerReferences[$projectId])) {
            return $this->customerReferences[$projectId];
        }

        $references = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->customers->list(new ListCustomersCommand(
                projectId: $projectId,
                cursor: $cursor,
                limit: self::PAGE_SIZE,
            ), $scope);

            foreach ($result->items as $customer) {
                $references[$customer->id] = $customer->externalRef;
            }

            $cursor = $result->nextCursor;

            if ($cursor === null) {
                break;
            }
        }

        $this->customerReferences[$projectId] = $references;

        return $references;
    }

    /**
     * The provider applications of the project, by provider code.
     *
     * @return array<string, string> provider code => the name the operator gave it
     */
    private function providerNames(string $projectId, AccessScope $scope): array
    {
        if (isset($this->providerNames[$projectId])) {
            return $this->providerNames[$projectId];
        }

        $names = [];

        foreach ($this->providerApplications->list(new ListProviderApplicationsCommand($projectId), $scope) as $application) {
            if ($application->label !== null && $application->label !== '') {
                $names[$application->providerCode] = $application->label;
            }
        }

        $this->providerNames[$projectId] = $names;

        return $names;
    }
}
