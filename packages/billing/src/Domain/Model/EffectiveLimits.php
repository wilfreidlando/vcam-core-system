<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0117 §3 — the allowances of a contract, by meter.
 *
 * This is the **sum** of the active supplements, and it is the only place
 * where that sum is computed: the metered call (ADR-0074 §2) and the
 * entitlements (ADR-0074 §4) both read it, so the two can never
 * disagree on what is left.
 *
 * The plan's own limit stays where it is — on the feature of the plan
 * version (ADR-0071 §3). The effective limit is the sum of the two:
 * `limitFor()` is the only method that adds them.
 */
final readonly class EffectiveLimits
{
    /**
     * The quantity added to each meter, by the active supplements of the
     * contract — those of the period in progress (ADR-0121).
     *
     * @var array<string, int>
     */
    private array $quantities;

    /**
     * The same quantity, counted over the whole contract — the
     * allowance of a counter that never resets (ADR-0071 §3), whose
     * window spans every period.
     *
     * @var array<string, int>
     */
    private array $lifetime;

    /**
     * @param array<string, int> $quantities the allowances of the period in progress
     * @param array<string, int>|null $lifetime the allowances of the whole contract
     */
    public function __construct(array $quantities, ?array $lifetime = null)
    {
        $this->quantities = $quantities;
        $this->lifetime = $lifetime ?? $quantities;
    }

    /**
     * The units added to a meter — zero when no supplement carries it.
     */
    public function quantityFor(string $meter): int
    {
        return $this->quantities[$meter] ?? 0;
    }

    /**
     * The effective limit of a meter: the limit of the plan version plus
     * the allowance of the supplements.
     *
     * A plan limit of `null` is an **unlimited** right (ADR-0071 §3): it
     * stays unlimited — a supplement on an unlimited meter grants
     * nothing, and the purchase of one is refused (ADR-0117 §2).
     *
     * ADR-0121 — the allowance follows the window of the counter: a
     * counter that resets with the period takes the allowance of the
     * period in progress, a counter that never resets takes the
     * allowance of the whole contract.
     */
    public function limitFor(string $meter, ?int $planLimit, ?FeatureReset $reset = null): ?int
    {
        if ($planLimit === null) {
            return null;
        }

        // A characteristic that says nothing about its reset resets with
        // the period — the rule the constructor of the plan feature
        // already applies.
        $allowance = ($reset ?? FeatureReset::Period) === FeatureReset::Never
            ? ($this->lifetime[$meter] ?? 0)
            : $this->quantityFor($meter);

        return $planLimit + $allowance;
    }

    public function isEmpty(): bool
    {
        return $this->quantities === [];
    }
}
