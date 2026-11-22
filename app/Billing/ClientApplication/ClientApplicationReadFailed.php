<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use RuntimeException;

/**
 * ADR-0133 §5 — la lecture de l'application a échoué.
 *
 * Chaque cas a **sa** raison, en français : l'opérateur ne voit jamais un
 * code HTTP ni une pile d'exception, il lit ce qu'il peut faire. Un
 * échec n'écrit rien — ni proposition, ni client.
 */
final class ClientApplicationReadFailed extends RuntimeException
{
    public static function notReachable(): self
    {
        return new self('L\'application n\'a pas répondu. Est-elle joignable ?');
    }

    public static function secretRefused(): self
    {
        return new self('Le secret ne correspond pas : le Core et l\'application n\'ont pas le même secret.');
    }

    public static function notYetBranchable(): self
    {
        return new self('Cette application ne parle pas encore au Core. Elle doit porter la version 1.7 du paquet.');
    }

    public static function endpointGone(): self
    {
        return new self('Le point d\'entrée des événements n\'existe plus. Enregistrez-le à nouveau.');
    }

    public static function noFormDeclared(): self
    {
        return new self('L\'application ne déclare aucune forme de locataire.');
    }

    public static function cannotListTenants(): self
    {
        return new self('L\'application ne sait pas lister ses locataires.');
    }

    public static function invalidForm(string $reason): self
    {
        return new self('L\'application a proposé une forme que le Core refuse : '.$reason);
    }

    public static function unreadable(): self
    {
        return new self('L\'application a répondu quelque chose que le Core ne sait pas lire.');
    }

    public static function tooLarge(): self
    {
        return new self('L\'application a répondu plus que le Core n\'accepte de lire.');
    }
}
