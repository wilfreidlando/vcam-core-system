<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use InvalidArgumentException;

/**
 * The delivery channel a caller asks for (`whatsapp`, `sms`, `email`,
 * `push`, `in_app`) — mirrors `Core\Payment\Contracts\PaymentChannel`:
 * an open, validated-string value object rather than a closed enum,
 * because the set of channels grows without a Core code change.
 *
 * The spec allows a literal `auto` channel (best channel for the
 * recipient, chosen by the Core). Not implemented here (ADR-0041) — a
 * caller must name an explicit channel.
 */
final readonly class NotificationChannel
{
    private const PATTERN = '/^[a-z][a-z0-9_]*$/';

    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Invalid notification channel '{$value}': must be lowercase, underscore-separated, starting with a letter."
            );
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
