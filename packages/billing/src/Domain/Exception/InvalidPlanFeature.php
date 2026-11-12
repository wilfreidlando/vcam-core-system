<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §3 — the features are NAMED by the project and TYPED by the
 * Core.
 *
 * The Core knows no feature name: it checks the format of the key and the
 * shape of the definition. Each factory states one of those rules, so the
 * API layer can turn the failure into a precise RFC 7807 problem detail
 * (`app/Http/ExceptionProblemMapping.php`) instead of a generic "invalid
 * payload".
 */
final class InvalidPlanFeature extends RuntimeException
{
    /**
     * @param string $feature the key of the feature the Core refuses
     */
    public function __construct(
        string $message,
        public readonly string $feature,
    ) {
        parent::__construct($message);
    }

    public static function becauseBadKey(string $key): self
    {
        return new self(
            "The feature key \"{$key}\" does not match the expected format ^[a-z0-9_.]{1,64}$.",
            $key,
        );
    }

    public static function becauseBadDefinition(string $key): self
    {
        return new self("The feature \"{$key}\" has a malformed definition: a type is required.", $key);
    }

    public static function becauseUnknownType(string $key, string $type): self
    {
        return new self("The feature \"{$key}\" declares the unknown type \"{$type}\".", $key);
    }

    public static function becauseBooleanWithoutEnabled(string $key): self
    {
        return new self("The boolean feature \"{$key}\" must declare an \"enabled\" flag.", $key);
    }

    public static function becauseBadMeter(string $key): self
    {
        return new self(
            "The limit feature \"{$key}\" must declare a \"meter\" matching ^[a-z0-9_.]{1,64}$.",
            $key,
        );
    }

    public static function becauseBadLimit(string $key): self
    {
        return new self(
            "The limit feature \"{$key}\" must declare a \"limit\" that is an integer of at least zero, or null for unlimited.",
            $key,
        );
    }

    public static function becauseUnknownReset(string $key, string $reset): self
    {
        return new self("The feature \"{$key}\" declares the unknown reset \"{$reset}\".", $key);
    }

    public static function becauseUnknownOverage(string $key, string $overage): self
    {
        return new self("The feature \"{$key}\" declares the unknown overage \"{$overage}\".", $key);
    }

    public static function becauseBadThreshold(string $key): self
    {
        return new self(
            "The feature \"{$key}\" must declare a \"threshold_bps\" between 1 and 10000, or null.",
            $key,
        );
    }
}
