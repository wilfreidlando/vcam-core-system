<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0130 §5 — le résultat d'une proposition de forme.
 *
 * La proposition dit **ce qui s'est passé** : une version a été créée, ou
 * la forme était déjà connue et rien n'a été écrit.
 *
 * Le cas n'est pas rare : une application qui se relance avec la même
 * forme (un `post-install`, un `deploy`) ne doit pas empiler des versions
 * que personne ne lira.
 */
final readonly class TenantSchemaProposal
{
    /**
     * @param TenantSchemaView $schema la déclaration en jeu — celle qui
     *                                 vient d'être proposée, ou celle
     *                                 qui était déjà connue
     * @param bool $created `true` quand une version a été créée ;
     *                      `false` quand la forme était déjà connue
     *                      et que rien n'a été écrit
     */
    public function __construct(
        public TenantSchemaView $schema,
        public bool $created,
    ) {}
}
