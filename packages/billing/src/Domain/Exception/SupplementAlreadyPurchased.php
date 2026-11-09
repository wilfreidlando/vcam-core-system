<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0119 §6 — le geste a déjà produit ce supplément.
 *
 * Ce n'est pas un refus : c'est le **rejeu** du même geste. La clé
 * d'idempotence est unique par projet, donc deux gestes concurrents de
 * même clé ne peuvent pas acheter deux fois : le second trouve la ligne
 * du premier.
 *
 * L'exception est levée par l'adaptateur (la violation de l'index unique)
 * et reçue par le use case, qui rend alors le supplément déjà acheté.
 */
final class SupplementAlreadyPurchased extends RuntimeException
{
    public static function because(string $idempotencyKey): self
    {
        return new self("This gesture already bought a supplement (key \"{$idempotencyKey}\").");
    }
}
