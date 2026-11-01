<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §1 — the attributes of a plan the Core refuses.
 *
 * The `code` is the project's own stable identifier: it is format-checked
 * so it can never be injected into a URL, a log or a cache key. The
 * `visibility` is one of the two values the Core knows.
 */
final class InvalidPlan extends RuntimeException
{
    public static function becauseBadCode(string $code): self
    {
        return new self(
            "The plan code \"{$code}\" does not match the expected format ^[a-z0-9][a-z0-9_-]{0,63}$ (ADR-0071 §1).",
        );
    }

    public static function becauseBadVisibility(string $visibility): self
    {
        return new self(
            "The plan visibility \"{$visibility}\" is unknown: expected \"public\" or \"private\" (ADR-0071 §1).",
        );
    }
}
