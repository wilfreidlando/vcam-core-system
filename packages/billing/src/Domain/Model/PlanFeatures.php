<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidPlanFeature;

/**
 * ADR-0071 §3 — the features of a plan version, as a map.
 *
 * The names are the PROJECT's: the Core checks their format, never a list
 * of them. The Core knows no "students" nor "whatsapp_messages" — those
 * are the examples of the ADR, not of the Core.
 *
 * The map is immutable: a published version is frozen (ADR-0071 §2), and a
 * plan that could be edited in place would make the price displayed, the
 * price billed and the price contracted drift apart.
 */
final readonly class PlanFeatures
{
    /**
     * @param array<string, FeatureDefinition> $features
     */
    private function __construct(private array $features) {}

    /**
     * Reads the wire shape of the `features` column of a plan version.
     *
     * @param array<array-key, mixed> $data the raw map, keyed by feature name
     */
    public static function fromArray(array $data): self
    {
        $features = [];

        foreach ($data as $key => $definition) {
            $name = (string) $key;

            if (preg_match('/^[a-z0-9_.]{1,64}$/', $name) !== 1) {
                throw InvalidPlanFeature::becauseBadKey($name);
            }

            if (! is_array($definition)) {
                throw InvalidPlanFeature::becauseBadDefinition($name);
            }

            /** @var array<array-key, mixed> $definition */
            $features[$name] = FeatureDefinition::fromArray($name, $definition);
        }

        return new self($features);
    }

    /**
     * The wire shape of the map, as `plan_versions.features` stores it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $features = [];

        foreach ($this->features as $name => $definition) {
            $features[$name] = $definition->toArray();
        }

        return $features;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->features);
    }

    public function get(string $key): ?FeatureDefinition
    {
        return $this->features[$key] ?? null;
    }

    /**
     * @return array<string, FeatureDefinition>
     */
    public function all(): array
    {
        return $this->features;
    }

    public function count(): int
    {
        return count($this->features);
    }
}
