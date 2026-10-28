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
        'G4b' => [
            'rule' => 'The customers table holds no column outside its white-list, and its personal values are encrypted at rest',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Schema/CustomersColumnsTest.php',
            'status' => self::ACTIVE,
        ],
        'G4c' => [
            'rule' => 'The invoice tables hold no column outside their white-list, and the customer frozen on an invoice is encrypted at rest',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Schema/InvoicesColumnsTest.php',
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
            'status' => self::ACTIVE,
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
            'status' => self::ACTIVE,
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
            'status' => self::ACTIVE,
        ],
        'G9b' => [
            'rule' => 'The API documentation carries only the external integrations: the operator plane belongs to the console, never to an integrator\'s document (ADR-0049)',
            'tool' => 'Pest',
            'enforced_by' => 'tests/Architecture/OpenApiSurfaceTest.php',
            'status' => self::ACTIVE,
        ],
        'G10' => [
            'rule' => 'No consumer contract regression',
            'tool' => 'Pest fixtures (Pact deferred until a second consumer exists)',
            'enforced_by' => 'tests/Contract',
            'status' => self::PENDING_CODE,
            'lot' => 'Lot 17 — bascule et extinction de la plateforme transitoire',
        ],
        'G11' => [
            'rule' => 'Ledger invariants: sum-zero, derived balances, concurrency, idempotent replay',
            'tool' => 'Pest + PostgreSQL',
            'enforced_by' => 'tests/Invariants',
            'status' => self::ACTIVE,
        ],
        'G13' => [
            'rule' => 'Financial isolation: a ledger entry never crosses projects (ADR-0076 §5)',
            'tool' => 'PostgreSQL trigger + Pest',
            'enforced_by' => 'tests/Invariants/LedgerProjectIsolationTest.php',
            'status' => self::ACTIVE,
        ],
        'G14' => [
            'rule' => 'No usable secret in an API response, except once in the response that creates it (ADR-0081 S-07)',
            'tool' => 'Pest',
            'enforced_by' => 'tests/Architecture/SecretLeakGuardTest.php',
            'status' => self::ACTIVE,
        ],
        'G15' => [
            'rule' => 'Every console route carrying an identifier gets a generated isolation case: another organization\'s resource answers 404, never 403 (ADR-0081 S-01/S-04)',
            'tool' => 'Pest (generated cases)',
            'enforced_by' => 'tests/Architecture/IdorGuardTest.php',
            'status' => self::ACTIVE,
        ],
        'G16' => [
            'rule' => 'Every read port the console calls takes the organization access scope, and no template calls a port (ADR-0069 §2)',
            'tool' => 'PHPStan (custom rule) + Pest',
            'enforced_by' => 'tools/PhpStan/Rules/AdminQueriesAreScopedRule.php',
            'status' => self::ACTIVE,
        ],
    ];
}
