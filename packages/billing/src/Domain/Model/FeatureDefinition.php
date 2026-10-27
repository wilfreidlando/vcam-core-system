<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidPlanFeature;

/**
 * ADR-0071 §3 — the definition of ONE feature of a plan version.
 *
 * The Core knows no feature name: the project names its features, the Core
 * types them. Two types exist in v1 — a switch (`boolean`) and a counter
 * (`limit`) — and the registry of the types is extensible (ADR-0090).
 *
 * A `boolean` feature is a flag: it is on or off.
 *
 * A `limit` feature counts on a `meter` the project owns, and says what
 * happens past the limit: `block` (refuse) or `allow` (record and signal).
 * A `limit` of `null` means unlimited. The `reset` says when the counter
 * goes back to zero — at every period, or never (a gauge).
 *
 * The `threshold_bps` is the alert threshold, in basis points (8000 = 80 %).
 */
final readonly class FeatureDefinition
{
    public function __construct(
        public FeatureType $type,
        public ?bool $enabled,
        public ?string $meter,
        public ?int $limit,
        public ?FeatureReset $reset,
        public ?FeatureOverage $overage,
        public ?int $thresholdBps,
    ) {}

    /**
     * Reads the wire shape of a feature and checks it against the rules of
     * ADR-0071 §3.
     *
     * @param array<array-key, mixed> $data the raw definition of the feature
     */
    public static function fromArray(string $key, array $data): self
    {
        $type = $data['type'] ?? null;

        if (! is_string($type)) {
            throw InvalidPlanFeature::becauseBadDefinition($key);
        }

        $featureType = FeatureType::tryFrom($type);

        if ($featureType === null) {
            throw InvalidPlanFeature::becauseUnknownType($key, $type);
        }

        return $featureType === FeatureType::Boolean
            ? self::booleanFeature($key, $data)
            : self::limitFeature($key, $data);
    }

    /**
     * The wire shape of a feature, as `plan_versions.features` stores it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type === FeatureType::Boolean) {
            return [
                'type' => $this->type->value,
                'enabled' => $this->enabled,
            ];
        }

        $definition = [
            'type' => $this->type->value,
            'meter' => $this->meter,
            'limit' => $this->limit,
            'reset' => $this->reset?->value,
            'overage' => $this->overage?->value,
        ];

        if ($this->thresholdBps !== null) {
            $definition['threshold_bps'] = $this->thresholdBps;
        }

        return $definition;
    }

    /**
     * A counter with no ceiling: the feature is there, but it never
     * blocks.
     */
    public function isUnlimited(): bool
    {
        return $this->type === FeatureType::Limit && $this->limit === null;
    }

    /**
     * A switch: it must say whether it is on.
     *
     * @param array<array-key, mixed> $data
     */
    private static function booleanFeature(string $key, array $data): self
    {
        $enabled = $data['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw InvalidPlanFeature::becauseBooleanWithoutEnabled($key);
        }

        return new self(
            type: FeatureType::Boolean,
            enabled: $enabled,
            meter: null,
            limit: null,
            reset: null,
            overage: null,
            thresholdBps: null,
        );
    }

    /**
     * A counter: it must name the meter it consumes, and it may say how
     * much of it is allowed.
     *
     * @param array<array-key, mixed> $data
     */
    private static function limitFeature(string $key, array $data): self
    {
        $meter = $data['meter'] ?? null;

        if (! is_string($meter) || preg_match('/^[a-z0-9_.]{1,64}$/', $meter) !== 1) {
            throw InvalidPlanFeature::becauseBadMeter($key);
        }

        // A missing limit is an unlimited one; a limit that is present must
        // be a count of minor units of consumption, never negative.
        $limit = $data['limit'] ?? null;

        if ($limit !== null && (! is_int($limit) || $limit < 0)) {
            throw InvalidPlanFeature::becauseBadLimit($key);
        }

        $reset = FeatureReset::Period;
        $rawReset = $data['reset'] ?? null;

        if ($rawReset !== null) {
            $resetValue = is_string($rawReset) ? $rawReset : get_debug_type($rawReset);
            $parsedReset = FeatureReset::tryFrom($resetValue);

            if ($parsedReset === null) {
                throw InvalidPlanFeature::becauseUnknownReset($key, $resetValue);
            }

            $reset = $parsedReset;
        }

        $overage = FeatureOverage::Block;
        $rawOverage = $data['overage'] ?? null;

        if ($rawOverage !== null) {
            $overageValue = is_string($rawOverage) ? $rawOverage : get_debug_type($rawOverage);
            $parsedOverage = FeatureOverage::tryFrom($overageValue);

            if ($parsedOverage === null) {
                throw InvalidPlanFeature::becauseUnknownOverage($key, $overageValue);
            }

            $overage = $parsedOverage;
        }

        $thresholdBps = $data['threshold_bps'] ?? null;

        if ($thresholdBps !== null && (! is_int($thresholdBps) || $thresholdBps < 1 || $thresholdBps > 10000)) {
            throw InvalidPlanFeature::becauseBadThreshold($key);
        }

        return new self(
            type: FeatureType::Limit,
            enabled: null,
            meter: $meter,
            limit: $limit,
            reset: $reset,
            overage: $overage,
            thresholdBps: $thresholdBps,
        );
    }
}
