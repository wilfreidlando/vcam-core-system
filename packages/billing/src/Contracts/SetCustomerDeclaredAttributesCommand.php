<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0122 §2, §5 — les valeurs déclarées que l'opérateur enregistre.
 *
 * `$values` porte **l'ensemble** des valeurs : le geste remplace ce que
 * le client porte, il n'y ajoute rien. Une clé déclarée par la forme en
 * force et obligatoire doit être là ; une clé qui ne l'est pas est
 * refusée.
 */
final readonly class SetCustomerDeclaredAttributesCommand
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public string $projectId,
        public string $customerId,
        public array $values,
        public string $actor,
        public ?string $correlationId,
    ) {}
}
