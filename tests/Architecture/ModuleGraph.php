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

        // Identity may write to Audit — ADR-0029. Audit itself stays a leaf.
        'identity' => ['support', 'audit'],
        'events' => ['support'],
        'audit' => ['support'],

        // ── Capability modules ─────────────────────────────────────────────
        'parties' => ['support', 'identity'],
        // identity: ADR-0033 — the balance-read endpoint checks scopes via
        // Core\Identity\Contracts\ProjectContext.
        'wallet' => ['support', 'ledger', 'parties', 'events', 'identity'],
        'fees' => ['support', 'ledger', 'parties'],
        'payment' => ['support', 'ledger', 'fees', 'identity', 'parties', 'events', 'wallet'],
        'webhook' => ['support', 'identity', 'events'],
        // billing: ADR-0074 §5 — the notification asks the quota authority
        // before it exists (port QuotaGuard, adapter in Infrastructure).
        'notification' => ['support', 'identity', 'parties', 'events', 'billing'],
        'billing' => ['support', 'identity', 'payment', 'ledger', 'events'],
        // ADR-0075 — the hosted customer portal: billing for what it shows,
        // payment for the channel a customer pays with, audit for the sessions.
        // notification: ADR-0100 — the code of the direct login leaves through
        // SendTextNotification (portal → notification → billing: no cycle).
        'portal' => ['support', 'identity', 'billing', 'payment', 'audit', 'notification'],
        // ADR-0101 — the export of a project (ADR-0088 §3): a hub like `admin`, but it
        // only reads what the public API already shows, through each module's Contracts.
        'export' => ['support', 'identity', 'audit', 'ledger', 'billing', 'payment', 'notification', 'wallet'],
        'storage' => ['support', 'identity'],
        'developer-platform' => ['support', 'identity'],

        // The operator console reads across every capability by design.
        'admin' => [
            'support', 'identity', 'parties', 'ledger', 'wallet', 'payment',
            'fees', 'events', 'webhook', 'notification', 'audit', 'billing',
            'storage', 'developer-platform',
            // ADR-0097 — the console edits the settings of a project's portal.
            'portal',
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
        'portal' => 'Portal',
        'export' => 'Export',
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
