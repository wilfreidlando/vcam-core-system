<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

use Core\Billing\Contracts\EntitlementsView;

/**
 * ADR-0119 §4 — la page « Utilisation » : les droits, les packs
 * proposés, et le nonce du formulaire.
 *
 * L'offre **ne dépend pas de l'usage** : les groupes portent les
 * packs du catalogue même quand le quota n'est pas atteint. Une
 * offre conditionnée à un seuil que le client ne vérifie pas ne lui
 * apprendrait rien (ADR-0119 §4).
 */
final readonly class PortalUsage
{
    /**
     * @param list<PortalPackGroup> $groups
     */
    public function __construct(
        public ?EntitlementsView $entitlements,
        public array $groups,
        /** Le nonce du formulaire : il rend le rejou sans effet (ADR-0119 §6). */
        public string $nonce,
    ) {}
}
