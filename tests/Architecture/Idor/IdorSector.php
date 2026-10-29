<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * Which bounded context owns which console route.
 *
 * The generated IDOR suite covers **every** console route carrying an
 * identifier — 167 cases, eighty seconds. An agent working on one
 * bounded context does not need the whole suite: it needs the cases of the
 * routes it touched. This class is the single place that says which
 * context owns which route, so a sector can be run alone.
 *
 * The sector is also the data-set label: a case is named
 * `billing::admin.projects.invoices.index`, and
 * `--filter='billing::'` selects exactly that context's routes.
 */
final class IdorSector
{
    /**
     * The console sectors — the bounded contexts that own console routes.
     *
     * @var list<string>
     */
    public const ALL = [
        'billing',
        'identity',
        'wallet',
        'notification',
        'payment',
        'ledger',
        'webhook',
    ];

    /**
     * Route prefix (longest match wins) => the sector that owns it.
     *
     * @var array<string, string>
     */
    private const OWNERS = [
        'admin.projects.customers' => 'billing',
        'admin.projects.plans' => 'billing',
        'admin.projects.subscriptions' => 'billing',
        'admin.projects.invoices' => 'billing',
        'admin.projects.receipts' => 'billing',
        // The commerce settings and the usage of a project belong to `billing`;
        // the portal page edits `portal`'s settings but is part of the same sector run.
        'admin.projects.portal' => 'billing',
        'admin.projects.commerce-settings' => 'billing',
        'admin.projects.usage' => 'billing',
        'admin.projects.indicators' => 'billing',
        'admin.projects.wallets' => 'wallet',
        'admin.projects.notifications' => 'notification',
        'admin.projects.api-keys' => 'identity',
        'admin.projects.provider-applications' => 'identity',
        'admin.projects.webhook-endpoints' => 'identity',
        'admin.projects.channel-sessions' => 'identity',
        'admin.projects.show' => 'identity',
        'admin.projects.edit' => 'identity',
        'admin.projects.update' => 'identity',
        'admin.organizations' => 'identity',
        'admin.operators' => 'identity',
        'admin.payments' => 'payment',
        'admin.projects.payments' => 'payment',
        'admin.reconciliation' => 'payment',
        'admin.money-exceptions' => 'ledger',
        'admin.ledger-adjustments' => 'ledger',
        'admin.webhook-deliveries' => 'webhook',
        'admin.channel-sessions' => 'identity',
    ];

    /**
     * The sector that owns a route.
     */
    public static function of(string $route): string
    {
        $owner = null;
        $length = 0;

        foreach (self::OWNERS as $prefix => $sector) {
            if (str_starts_with($route, $prefix) && strlen($prefix) > $length) {
                $owner = $sector;
                $length = strlen($prefix);
            }
        }

        // A route nobody claimed is a hole in the map, not a sector of its
        // own — the coverage test says so loudly rather than letting it
        // pass unnoticed.
        return $owner ?? 'unmapped';
    }

    /**
     * The label of a case: the sector, then the route.
     */
    public static function label(string $route): string
    {
        return self::of($route).'::'.$route;
    }

    /**
     * @return list<string>
     */
    public static function sectors(): array
    {
        return self::ALL;
    }
}
