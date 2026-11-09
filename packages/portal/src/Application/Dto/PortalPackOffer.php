<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0119 §5 — une offre de recharge, lue par le client.
 *
 * Le `code` est la clé sous laquelle le projet range son offre : il
 * permet au formulaire de nommer le pack choisi, il n'est **jamais**
 * affiché.
 *
 * L'argent est en unités mineures, avec la devise du projet (G5).
 */
final readonly class PortalPackOffer
{
    public function __construct(
        public string $code,
        /** Le libellé du projet : « Pack de 50 messages ». */
        public string $label,
        public int $quantity,
        public int $totalMinor,
        public string $currency,
    ) {}
}
