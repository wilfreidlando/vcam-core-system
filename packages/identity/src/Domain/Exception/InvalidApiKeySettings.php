<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Exception;

use RuntimeException;

/** ADR-0082 — a key setting the caller chose is not acceptable (422, never 500). */
final class InvalidApiKeySettings extends RuntimeException
{
    public static function cidr(string $value): self
    {
        return new self(sprintf('« %s » n\'est pas un bloc réseau valide (exemples : 203.0.113.0/24, 2001:db8::/32).', $value));
    }

    public static function name(): self
    {
        return new self('Le nom de la clé est obligatoire (80 caractères au plus).');
    }

    /** @param list<string> $unknown */
    public static function unknownScopes(array $unknown): self
    {
        return new self('Portée(s) inconnue(s) : '.implode(', ', $unknown).'.');
    }

    public static function expiryInThePast(): self
    {
        return new self('L\'expiration d\'une clé doit être dans le futur.');
    }

    public static function graceDays(int $min, int $max): self
    {
        return new self(sprintf('Le délai avant révocation de l\'ancienne clé doit être compris entre %d et %d jours.', $min, $max));
    }

    public static function reason(): self
    {
        return new self('Le motif de révocation est obligatoire.');
    }

    public static function tooManyCidrs(int $max): self
    {
        return new self(sprintf('Une clé accepte au plus %d blocs réseau.', $max));
    }
}
