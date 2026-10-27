<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use DomainException;

/**
 * ADR-0076 §5 — a correction is a counter-passation: it names the
 * transaction it reverses. This refusal says why the transaction a
 * correction points at cannot be the one it reverses.
 *
 * A separate type from `LedgerAdjustmentTransitionRefused` because the
 * console shows it on its own field: the transaction the correction
 * reverses is not the amount, not the motive, and not the accounts — it
 * is the very transaction the correction hangs on.
 */
final class LedgerAdjustmentReversalRefused extends DomainException
{
    public static function required(string $id): self
    {
        return new self(sprintf('La correction %s doit nommer la transaction qu\'elle contre-passe.', $id));
    }

    public static function notFound(string $transactionId): self
    {
        return new self(sprintf('La transaction %s n\'existe pas : il n\'y a rien à contrer-passer.', $transactionId));
    }

    public static function anotherProject(string $transactionId): self
    {
        return new self(sprintf(
            'La transaction %s appartient à un autre projet : une correction ne contrer-passe que les écritures de son propre projet.',
            $transactionId,
        ));
    }

    public static function alreadyACorrection(string $transactionId): self
    {
        return new self(sprintf(
            'La transaction %s est elle-même une correction : une correction ne contrer-passe jamais une autre correction.',
            $transactionId,
        ));
    }
}
