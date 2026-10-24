<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use DomainException;

/** A resolution step an exception's current state does not allow — the message is shown to the operator as is. */
final class MoneyExceptionTransitionRefused extends DomainException
{
    public static function notOpen(string $id, string $status): self
    {
        return new self(sprintf('L\'exception %s n\'est plus ouverte (statut : %s).', $id, $status));
    }

    public static function notAwaitingApproval(string $id, string $status): self
    {
        return new self(sprintf('L\'exception %s n\'attend pas de validation (statut : %s).', $id, $status));
    }

    public static function resolutionNotAllowed(string $id, string $resolution): self
    {
        return new self(sprintf('La résolution « %s » n\'est pas permise pour l\'exception %s.', $resolution, $id));
    }

    public static function referenceRequired(string $id): self
    {
        return new self(sprintf('Un remboursement hors plateforme exige sa référence (exception %s).', $id));
    }

    public static function noteRequired(string $id): self
    {
        return new self(sprintf('Un motif est obligatoire pour résoudre l\'exception %s.', $id));
    }

    public static function sameApprover(string $id): self
    {
        return new self(sprintf('La validation doit venir d\'une autre personne que l\'auteur de la résolution (exception %s).', $id));
    }
}
