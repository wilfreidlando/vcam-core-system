<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Core\Billing\Contracts\PlanView;

/**
 * The counters a project counts — **declared once, chosen everywhere**.
 *
 * The operator writes a counter in exactly one place: « Réglages du commerce
 * → Limites d'usage ». Everywhere else — the packs, the quotas of the
 * offers — the counter is **chosen** from that list.
 *
 * A counter typed twice is a counter misspelled twice, and a misspelled
 * counter counts nothing: the quota is silently never enforced. Choosing it
 * removes the mistake, not the possibility.
 *
 * The counters the project's offers already carry are added to the list:
 * they are counters of the project, and the operator must be able to pick
 * them again instead of typing them.
 */
trait ResolvesProjectCounters
{
    /**
     * @param array<string, string> $mappings capability => counter
     * @param list<string> $used the counters the offers and the packs already use
     * @return list<string>
     */
    private function counters(array $mappings, array $used): array
    {
        $counters = [];

        foreach ($mappings as $meter) {
            $counters[$meter] = true;
        }

        foreach ($used as $meter) {
            $counters[$meter] = true;
        }

        $names = array_keys($counters);
        sort($names);

        return $names;
    }

    /**
     * The counters the project's offers already carry.
     *
     * @param list<PlanView> $plans
     * @return list<string>
     */
    private function countersOfOffers(array $plans): array
    {
        $counters = [];

        foreach ($plans as $plan) {
            foreach ($plan->versions as $version) {
                foreach ($version->features as $definition) {
                    if (! is_array($definition)) {
                        continue;
                    }

                    $meter = $definition['meter'] ?? null;

                    if (is_string($meter) && $meter !== '') {
                        $counters[$meter] = true;
                    }
                }
            }
        }

        return array_keys($counters);
    }
}
