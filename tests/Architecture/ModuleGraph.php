<?php

declare(strict_types=1);

namespace Tests\Architecture;

/**
 * The allowed dependency graph between bounded contexts — the single source of
 * truth.
 *
 * Three artefacts must agree with this array, and ComposerGraphTest fails if any
 * of them drifts:
 *
 *   1. `deptrac-modules.yaml`      — what the code may reference
 *   2. `packages/<m>/composer.json` — what the module declares it needs
 *   3. the directories under `packages/`
 *
 * Changing this graph is an architectural decision, not a refactor: it requires
 * an ADR and architecture-committee approval (docs/gouvernance/comite-architecture.md).
 */
final class ModuleGraph
{
    /**
     * Module directory name => modules it may depend on.
     *
     * Every module may additionally depend on `support` (the shared kernel),
     * which is listed explicitly so the composer manifests can be compared
     * literally.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        // ── Bottom of the stack ────────────────────────────────────────────
        // The shared kernel: cross-cutting primitives, no business capability.
        'support' => [],

        // The financial core. This empty list is the most important line in the
        // repository: nothing may push a dependency onto the ledger.
        'ledger' => ['support'],

        'identity' => ['support'],
        'events' => ['support'],
        'audit' => ['support'],

        // ── Capability modules ─────────────────────────────────────────────
        'parties' => ['support', 'identity'],
        'wallet' => ['support', 'ledger', 'parties', 'events'],
        'fees' => ['support', 'ledger', 'parties'],
        'payment' => ['support', 'ledger', 'fees', 'identity', 'parties', 'events', 'wallet'],
        'webhook' => ['support', 'identity', 'events'],
        'notification' => ['support', 'identity', 'parties', 'events'],
        'billing' => ['support', 'identity', 'payment', 'ledger', 'events'],
        'storage' => ['support', 'identity'],
        'developer-platform' => ['support', 'identity'],

        // The operator console reads across every capability by design.
        'admin' => [
            'support', 'identity', 'parties', 'ledger', 'wallet', 'payment',
            'fees', 'events', 'webhook', 'notification', 'audit', 'billing',
            'storage', 'developer-platform',
        ],
    ];

    /**
     * Directory name => Deptrac layer name.
     *
     * @var array<string, string>
     */
    public const LAYER_NAMES = [
        'support' => 'Support',
        'identity' => 'Identity',
        'parties' => 'Parties',
        'ledger' => 'Ledger',
        'wallet' => 'Wallet',
        'payment' => 'Payment',
        'fees' => 'Fees',
        'events' => 'Events',
        'webhook' => 'Webhook',
        'notification' => 'Notification',
        'audit' => 'Audit',
        'billing' => 'Billing',
        'storage' => 'Storage',
        'developer-platform' => 'DeveloperPlatform',
        'admin' => 'Admin',
    ];

    /**
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_keys(self::ALLOWED);
    }
}
