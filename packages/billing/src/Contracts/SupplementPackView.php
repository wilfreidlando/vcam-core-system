<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0119 §2 — un pack du catalogue d'un projet, tel qu'il est lu.
 *
 * Le pack est une **offre** : ce que le projet propose à ses clients.
 * Le `code` est la clé sous laquelle le projet range son offre ; le
 * client final ne le voit jamais.
 *
 * L'argent est en unités mineures (G5).
 */
final readonly class SupplementPackView
{
    public function __construct(
        public string $code,
        public string $meter,
        public int $quantity,
        public int $unitPriceMinor,
        public int $totalMinor,
        public string $label,
    ) {}
}
