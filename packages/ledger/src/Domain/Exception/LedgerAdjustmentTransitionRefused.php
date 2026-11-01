<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use DomainException;

/**
 * A step the correction's current state does not allow — the message is
 * shown to the operator as is.
 */
final class LedgerAdjustmentTransitionRefused extends DomainException
{
    public static function notProposed(string $id, string $status): self
    {
        return new self(sprintf('La correction %s n\'attend pas de validation (statut : %s).', $id, $status));
    }

    public static function sameApprover(string $id): self
    {
        return new self(sprintf('La validation doit venir d\'une autre personne que l\'auteur de la correction %s.', $id));
    }

    public static function amountMustBePositive(string $id): self
    {
        return new self(sprintf('Le montant d\'une correction doit être strictement positif (correction %s).', $id));
    }

    public static function reasonRequired(string $id): self
    {
        return new self(sprintf('Un motif est obligatoire pour proposer la correction %s.', $id));
    }

    /**
     * A correction moves money between two DIFFERENT accounts. The same
     * account on both sides would book a debit and a credit on it: no
     * balance moves, and the audit trail would record a "correction" that
     * corrected nothing.
     */
    public static function sameAccount(string $id): self
    {
        return new self(sprintf('La correction %s doit porter sur deux comptes différents.', $id));
    }

    /**
     * The balance of a party belongs to the `wallet` module, which is the
     * only one allowed to update it — and it does so in the same
     * transaction as the ledger entries that justify it. A correction
     * writes ONLY to the ledger: it would silently desynchronize the
     * ledger's `wallet` account from the real balance of the wallet, with
     * nothing to notice the gap.
     */
    public static function walletAccountForbidden(string $id): self
    {
        return new self(sprintf(
            'Une correction d\'exploitation ne peut pas porter sur le compte d\'une partie (wallet) : '
            .'le solde d\'une partie n\'est mis à jour que par le module wallet (correction %s).',
            $id,
        ));
    }
}
