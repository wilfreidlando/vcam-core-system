<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0122 §3 — les types que le Core connaît.
 *
 * Le Core ne sait pas ce qu'est une auto-école ; il sait qu'une valeur est
 * un texte, un nombre, un booléen, une date. La liste est **fermée** : une
 * application qui veut un type de plus le déclare dans ses propres termes,
 * ou le Core l'ouvre par un nouvel ADR — jamais au hasard.
 *
 * - `string` — une valeur courte (une référence, un code) ;
 * - `text` — une valeur libre (une adresse, un nom long) ;
 * - `integer` — un nombre entier ;
 * - `decimal` — un nombre décimal (le Core le stocke en clair, comme une
 *   valeur d'exploitation : ce n'est ni un montant, ni une monnaie) ;
 * - `boolean` — vrai ou faux ;
 * - `date` — une date sans heure ;
 * - `datetime` — un instant ;
 * - `email` — une adresse (valeur d'exploitation, **jamais** la source
 *   `core_identity.email`, qui est réservée) ;
 * - `phone` — un numéro ;
 * - `url` — une adresse web.
 */
enum TenantSchemaFieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';
}
