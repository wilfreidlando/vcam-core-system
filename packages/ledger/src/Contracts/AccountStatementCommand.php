<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ADR-0076 §4 — "relevé avec solde d'ouverture et de clôture".
 *
 * The account is designated the way `ListAccountEntriesCommand`
 * designates it — by its kind, its reference and its currency inside a
 * project — never by its id: the id is an implementation detail of the
 * chart of accounts, the composite is what a caller knows.
 */
final readonly class AccountStatementCommand
{
    /**
     * The currency of the statement, in upper case (G5: three
     * characters, always upper case — `currencies.code` is upper case,
     * and a lower-case currency would match no account at all and
     * return an EMPTY statement, which reads as "this account never
     * moved").
     */
    public string $currency;

    /**
     * @param string $kind Lowercase, underscore-separated (e.g. "wallet", "clearing").
     * @param string $reference The kind-specific identifier (e.g. a wallet id), or "" for a project-level account.
     * @param DateTimeImmutable $from inclusive
     * @param DateTimeImmutable $to exclusive
     */
    public function __construct(
        public string $projectId,
        public string $kind,
        public string $reference,
        string $currency,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public ?string $cursor = null,
        public int $limit = 50,
    ) {
        $normalized = strtoupper(trim($currency));

        if ($normalized === '') {
            throw new InvalidArgumentException('La devise du relevé est obligatoire.');
        }

        $this->currency = $normalized;
    }
}
