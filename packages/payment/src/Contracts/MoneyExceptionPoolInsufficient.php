<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

/**
 * ADR-0077 §4 — the exception pool does not hold the money the
 * resolution wants to move.
 *
 * The pool (`payment_exceptions`) is credited when money is collected
 * and cannot be allocated, debited when it is settled. A resolution that
 * moves more than the pool holds takes money that was never collected:
 * the platform would create value out of nothing (ADR-0078 §2,
 * invariant 4 — "aucune valeur créée").
 *
 * The pool is ONE account per project and per currency (ADR-0076 §2) —
 * it is NOT a reserve held per exception. Two exceptions on the same
 * pool share it: the first one resolved spends it, and the second one
 * can then be refused even though the pool would have covered the total.
 * That behaviour is correct — the pool is a single account — but the
 * operator has to be told, or the refusal looks like missing money.
 *
 * This is a CONFLICT (409), not a bad request: the resolution itself is
 * legitimate — the money may well land in the pool a second later — but
 * it cannot be honoured as it stands, so nothing is moved and the
 * exception stays as it was.
 */
final class MoneyExceptionPoolInsufficient extends RuntimeException
{
    public static function forException(
        string $exceptionId,
        string $currency,
        int $availableMinor,
        int $requestedMinor,
    ): self {
        return new self(sprintf(
            'Le compte d\'exceptions d\'argent (%s) ne porte que %d %s : impossible d\'en déplacer %d pour l\'exception %s. Ce compte est un compte unique par projet et par devise, ce n\'est pas une réserve par exception : deux exceptions du même projet et de la même devise se partagent le même solde.',
            $currency,
            $availableMinor,
            $currency,
            $requestedMinor,
            $exceptionId,
        ));
    }
}
