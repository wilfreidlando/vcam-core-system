<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0122 §1 — a declared form the Core refuses.
 *
 * The Core only knows **names**: a key, a label, a type, an obligation, a
 * length, a source. It therefore refuses a declaration whose shape does
 * not hold: a pattern that could authorise a reference outside its
 * alphabet, a source that duplicates one of the Core's, a repeated key, a
 * number of fields or a size out of bounds.
 */
final class InvalidTenantSchema extends RuntimeException
{
    public static function becauseBadUniqueKey(string $uniqueKey): self
    {
        return new self(
            "The unique key \"{$uniqueKey}\" does not match the expected format ^[a-z][a-z0-9_]{0,63}$ (ADR-0122 §1).",
        );
    }

    public static function becauseBadVersion(int $version): self
    {
        return new self(
            "The version {$version} is invalid: the first declaration of a project is version 1 (ADR-0122 §2).",
        );
    }

    public static function becauseBadPattern(string $pattern, string $reason): self
    {
        return new self(
            "The pattern \"{$pattern}\" is refused: {$reason} (ADR-0122 §6).",
        );
    }

    public static function becauseBadFieldKey(string $key): self
    {
        return new self(
            "The field key \"{$key}\" does not match the expected format ^[a-z][a-z0-9_]{0,63}$ (ADR-0122 §3).",
        );
    }

    public static function becauseBadLabel(string $label): self
    {
        return new self(
            "The label \"{$label}\" is empty or too long: it holds 120 characters at most (ADR-0122 §3).",
        );
    }

    public static function becauseUnknownType(string $type): self
    {
        return new self(
            "The type \"{$type}\" is unknown: the Core only knows string, text, integer, decimal, boolean, date, datetime, email, phone and url (ADR-0122 §3).",
        );
    }

    public static function becauseBadMaxLength(int $maxLength): self
    {
        return new self(
            "The maximum length {$maxLength} is out of bounds: it runs from 1 to 4096 characters (ADR-0122 §3).",
        );
    }

    public static function becauseUnknownCoreSource(string $source): self
    {
        return new self(
            "The source \"{$source}\" names a field of the Core that the Core does not know: a source of the Core is one of core_identity.display_name, core_identity.email, core_identity.phone, core_identity.locale (ADR-0122 §5).",
        );
    }

    public static function becauseDuplicateFieldKey(string $key): self
    {
        return new self(
            "The key \"{$key}\" is declared twice in the same declaration (ADR-0122 §1).",
        );
    }

    public static function becauseTooManyFields(int $count): self
    {
        return new self(
            "The declaration holds {$count} fields: it holds 32 at most (ADR-0122 §1).",
        );
    }

    public static function becauseFieldsTooLarge(int $bytes): self
    {
        return new self(
            "The declaration weighs {$bytes} bytes: it weighs 4096 at most (ADR-0122 §1).",
        );
    }
}
