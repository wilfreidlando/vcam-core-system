<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0122 §2, §5 — a declared value the Core refuses.
 *
 * Only an **accepted** declaration is binding: without one, the Core does
 * not know which keys exist, nor which ones are mandatory. A value that
 * carries an undeclared key, that is missing while its field is
 * mandatory, or that is longer than declared, is refused.
 */
final class CustomerDeclaredAttributeRefused extends RuntimeException
{
    public static function becauseNoAcceptedSchema(string $projectId): self
    {
        return new self(
            "Project {$projectId} holds no accepted tenant schema: nothing can be declared (ADR-0122 §2).",
        );
    }

    public static function becauseKeyNotDeclared(string $key): self
    {
        return new self(
            "The key \"{$key}\" is not declared by the form in force (ADR-0122 §2).",
        );
    }

    public static function becauseRequiredValueMissing(string $key): self
    {
        return new self(
            "The key \"{$key}\" is mandatory and its value is empty (ADR-0122 §1).",
        );
    }

    public static function becauseValueTooLong(string $key, int $maxLength): self
    {
        return new self(
            "The value of \"{$key}\" is longer than the declared length ({$maxLength} characters) (ADR-0122 §1).",
        );
    }

    public static function becauseUnknownCustomer(): self
    {
        return new self(
            'No customer carries this identifier in the project (ADR-0122 §5).',
        );
    }

    public static function becauseProjectNotVisible(string $projectId): self
    {
        return new self(
            "Project {$projectId} is not visible to this caller (ADR-0069 §2).",
        );
    }
}
