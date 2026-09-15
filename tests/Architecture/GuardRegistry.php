<?php

declare(strict_types=1);

namespace Tests\Architecture;

/**
 * The anti-drift guardrails of docs/gouvernance, and where each is enforced.
 *
 * Several cannot fail yet — there is no ledger, no route, no provider adapter.
 * That is acceptable, but it must never be invisible: a guardrail everyone
 * believes is protecting them while it silently protects nothing is worse than
 * no guardrail, because it removes the vigilance that would otherwise apply.
 *
 * So each entry declares its status, and GuardCoverageTest checks that the
 * declaration is true: `pending-code` entries must be listed in
 * docs/architecture/garde-fous-inactifs.md with a delivery lot.
 *
 * Every `active` entry must also be exercised by tools/guards/verify-guards.sh,
 * which injects a real violation and requires the gate to reject it.
 */
final class GuardRegistry
{
    public const ACTIVE = 'active';

    /** Written and correct, but guards code that does not exist yet. */
    public const PENDING_CODE = 'pending-code';

    /**
     * @var array<string, array{rule: string, tool: string, enforced_by: string, status: string, lot?: string}>
     */
    public const GUARDS = [
        'G1' => [
            'rule' => 'No cross-module dependency outside the allowed graph',
            'tool' => 'Deptrac',
            'enforced_by' => 'deptrac-modules.yaml',
            'status' => self::ACTIVE,
        ],
        'G1b' => [
            'rule' => 'Declared composer dependencies match the allowed graph',
            'tool' => 'Pest',
            'enforced_by' => 'tests/Architecture/ComposerGraphTest.php',
            'status' => self::ACTIVE,
        ],
        'G2' => [
            'rule' => 'The Ledger depends on no other bounded context',
            'tool' => 'Deptrac + Pest',
            'enforced_by' => 'tests/Architecture/ComposerGraphTest.php',
            'status' => self::ACTIVE,
        ],
        'G3' => [
            'rule' => 'Layers point inward: Presentation/Infrastructure → Application → Domain',
            'tool' => 'Deptrac',
            'enforced_by' => 'deptrac-layers.yaml',
            'status' => self::ACTIVE,
        ],
        'G3b' => [
            'rule' => 'Domain and Contracts carry no framework type',
            'tool' => 'PHPat',
            'enforced_by' => 'tools/PhpStan/Architecture/DomainIsFrameworkFree.php',
            'status' => self::ACTIVE,
        ],
        'G3c' => [
            'rule' => 'A module reaches another module only through its Contracts/',
            'tool' => 'PHPat',
            'enforced_by' => 'tools/PhpStan/Architecture/ModulesMeetOnlyThroughContracts.php',
            'status' => self::ACTIVE,
        ],
        'G3d' => [
            'rule' => 'The shared kernel holds no persistence',
            'tool' => 'PHPat',
            'enforced_by' => 'tools/PhpStan/Architecture/SharedKernelStaysTechnical.php',
            'status' => self::ACTIVE,
        ],
        'G4' => [
            'rule' => 'The parties table holds no personally identifying column',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Schema/PartiesPiiTest.php',
            'status' => self::ACTIVE,
        ],
        'G5' => [
            'rule' => 'Money is stored as bigint minor units, never float or decimal',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Schema/MoneyColumnsTest.php',
            'status' => self::ACTIVE,
        ],
        'G6' => [
            'rule' => 'Raw provider statuses stay inside their adapter',
            'tool' => 'PHPStan (custom rule)',
            'enforced_by' => 'tools/PhpStan/Rules/ForbiddenProviderStatusLiteralRule.php',
            'status' => self::ACTIVE,
        ],
        'G6b' => [
            'rule' => 'Canonical status enums match the governance vocabulary exactly',
            'tool' => 'Pest',
            'enforced_by' => 'tests/Architecture/CanonicalStatusTest.php',
            'status' => self::PENDING_CODE,
            'lot' => 'Lot 3 — PaymentStatus, Lot 5 — NotificationStatus',
        ],
        'G6c' => [
            'rule' => 'Only the shared integration caller opens outbound HTTP',
            'tool' => 'PHPat',
            'enforced_by' => 'tools/PhpStan/Architecture/OutboundHttpIsCentralised.php',
            'status' => self::ACTIVE,
        ],
        'G7' => [
            'rule' => 'Every monetary endpoint requires an Idempotency-Key',
            'tool' => 'Pest (booted application)',
            'enforced_by' => 'tests/Architecture/IdempotencyTest.php',
            'status' => self::PENDING_CODE,
            'lot' => 'Lot 3 — first public monetary mutation route '.
                '(ADR-0033 §1: Lot 2 wallet mutations are internal-only, no HTTP route; '.
                'ADR-0033 §2 amends ADR-0025 — HTTP-level idempotency deferred to Lot 3)',
        ],
        'G8' => [
            'rule' => 'Business events are emitted only through the transactional outbox',
            'tool' => 'PHPat + PHPStan (custom rule)',
            'enforced_by' => 'tools/PhpStan/Rules/NoDirectEventDispatchRule.php',
            'status' => self::ACTIVE,
        ],
        'G8c' => [
            'rule' => 'The outbox row and the state change share one transaction',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Integration/OutboxTransactionalityTest.php',
            'status' => self::ACTIVE,
        ],
        'G9' => [
            'rule' => 'No OpenAPI breaking change without an API version bump',
            'tool' => 'oasdiff + Spectral',
            'enforced_by' => '.github/workflows/ci.yml',
            'status' => self::PENDING_CODE,
            'lot' => 'Lot 1 — first published endpoint',
        ],
        'G10' => [
            'rule' => 'No consumer contract regression',
            'tool' => 'Pest fixtures (Pact deferred until a second consumer exists)',
            'enforced_by' => 'tests/Contract',
            'status' => self::PENDING_CODE,
            'lot' => 'Lot 7 — pilot product integration',
        ],
        'G11' => [
            'rule' => 'Ledger invariants: sum-zero, derived balances, concurrency, idempotent replay',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Invariants',
            'status' => self::ACTIVE,
        ],
    ];
}
