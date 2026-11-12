<?php

declare(strict_types=1);

namespace App\Http\View;

use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PlanView;

/**
 * What an offer counts, read for the console.
 *
 * An offer carries its terms in its versions: the price, the period, and
 * the features — the things the platform counts for the project, and the
 * switches it offers. The console reads them here and says them in French:
 * what is counted, on which counter, up to which limit, when the counter
 * starts again, what happens past the limit, and where the project is
 * warned.
 *
 * An offer that counts nothing is said plainly: some projects measure
 * nothing at all, and that is a legitimate choice.
 *
 * The same rows serve the listing and the correction form: the form
 * prefills its blocks from them, so the operator sees exactly what the
 * draft holds before touching it.
 */
final class OfferCounting
{
    /**
     * The rows of each offer, keyed by the offer's code.
     *
     * @param list<PlanView> $plans
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byPlan(array $plans): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            $rows[$plan->code] = self::rows([$plan]);
        }

        return $rows;
    }

    /**
     * The rows of one version — the features it carries, block by block.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsOfVersion(PlanVersionView $version): array
    {
        $rows = [];

        foreach ($version->features as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            /** @var array<string, mixed> $definition */
            $rows[] = self::row($name, $definition);
        }

        return $rows;
    }

    /**
     * One row per feature, version by version.
     *
     * @param list<PlanView> $plans
     * @return list<array<string, mixed>>
     */
    public static function rows(array $plans): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            foreach ($plan->versions as $version) {
                foreach ($version->features as $name => $definition) {
                    if (! is_array($definition)) {
                        continue;
                    }

                    /** @var array<string, mixed> $definition */
                    $rows[] = self::row($name, $definition, $version->version);
                }
            }
        }

        return $rows;
    }

    /**
     * One feature, read for the operator.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function row(string $name, array $definition, ?int $version = null): array
    {
        $isLimit = ($definition['type'] ?? '') === 'limit';
        $counter = $definition['meter'] ?? null;
        $limit = $definition['limit'] ?? null;
        $alert = $definition['threshold_bps'] ?? null;
        $enabled = $definition['enabled'] ?? null;
        $reset = $definition['reset'] ?? null;
        $overage = $definition['overage'] ?? null;

        return [
            'version' => $version,
            'element' => $name,
            'type' => $isLimit ? 'limit' : 'boolean',
            'kind' => $isLimit ? 'Limite d\'usage' : 'Interrupteur',
            'isLimit' => $isLimit,
            'enabled' => is_bool($enabled) ? $enabled : null,
            'counter' => is_string($counter) && $counter !== '' ? $counter : null,
            'limit' => is_int($limit) ? $limit : null,
            'reset' => $isLimit && is_string($reset) ? $reset : null,
            'renews' => $isLimit
                ? (($reset ?? 'period') === 'period' ? 'À chaque période' : 'Jamais')
                : null,
            'overage' => $isLimit && is_string($overage) ? $overage : null,
            'over' => $isLimit
                ? (($overage ?? 'block') === 'block' ? 'Bloqué' : 'Autorisé et signalé')
                : null,
            'alert' => is_int($alert) ? $alert : null,
        ];
    }
}
