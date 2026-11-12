<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0122 §2 — le cycle de vie d'une déclaration de forme.
 *
 * - `pending` — proposée par l'application, l'opérateur ne l'a pas encore lue ;
 * - `accepted` — en force : c'est la seule forme que le Core rend et applique ;
 * - `superseded` — **n'est plus en force** : elle a été remplacée par une
 *   déclaration plus récente, ou elle a été refusée par l'opérateur.
 *
 * Le statut ne distingue donc pas « remplacée » de « refusée » : les deux
 * se lisent dans `accepted_at`, qui reste vide pour une déclaration
 * refusée. Une déclaration n'est jamais « ré-acceptée » : elle est
 * remplacée.
 */
enum TenantSchemaStatus: string
{
    case Pending = 'pending';
    // The word is also a raw WhatsApp delivery receipt: here it is a
    // canonical status of the Core, not a provider's vocabulary.
    // @phpstan-ignore core.providerStatusLeak
    case Accepted = 'accepted';
    case Superseded = 'superseded';
}
