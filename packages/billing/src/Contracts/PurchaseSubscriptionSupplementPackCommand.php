<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0119 §3 — l'achat d'un pack, comme le portail le demande.
 *
 * Le `packCode` désigne une offre du catalogue du projet ; le Core en
 * tire le compteur, la quantité et le prix — figés dans le supplément
 * (ADR-0117 §1).
 *
 * L'`idempotencyKey` est la clé du geste : le portail la dérive de son
 * formulaire, côté serveur (ADR-0119 §6). Un rejeu de la même clé rend le
 * **même** résultat — le même supplément, la même facture — au lieu
 * d'acheter deux fois.
 */
final readonly class PurchaseSubscriptionSupplementPackCommand
{
    /**
     * @param string $actor "portal:<uuid>" — qui a demandé l'achat
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public string $packCode,
        public string $idempotencyKey = '',
        public string $actor = 'project:unknown',
        public ?string $correlationId = null,
    ) {}
}
