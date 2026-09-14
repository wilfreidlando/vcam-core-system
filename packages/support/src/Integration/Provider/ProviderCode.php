<?php

declare(strict_types=1);

namespace Core\Support\Integration\Provider;

use InvalidArgumentException;

/**
 * Stable identifier for one outbound provider integration.
 *
 * Encapsulates the string code (e.g. `mtn-momo`, `cinetpay`) in a value
 * object so it cannot be confused with an arbitrary string. The string is
 * validated to be non-empty and lower-case to keep log lines and metric
 * labels consistent.
 */
final readonly class ProviderCode
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException('ProviderCode must be a non-empty string.');
        }
        if (str_contains($trimmed, ' ')) {
            throw new InvalidArgumentException(
                'ProviderCode must not contain whitespace.',
            );
        }

        return new self(strtolower($trimmed));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
