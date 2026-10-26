<?php

declare(strict_types=1);

namespace Core\Audit\Tests\Integration\Infrastructure\Persistence\Eloquent;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Contracts\AuditSearchCriteria;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0044: the read counterpart of `AuditLogger`, exercised end to end
 * against a real Postgres `audit_log` table — filters, ordering, and
 * pagination all depend on real query behaviour a fake could hide.
 */
final class EloquentAuditQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_filters_by_resource_and_orders_newest_first(): void
    {
        $logger = app(AuditLogger::class);
        $query = app(AuditQuery::class);

        $logger->record(new AuditEntry(
            actor: 'operator:1', action: 'api_key.issued', resource: 'project:a',
            before: null, after: ['x' => 1], context: [], correlationId: null,
            occurredAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        ));
        $logger->record(new AuditEntry(
            actor: 'operator:1', action: 'api_key.revoked', resource: 'project:a',
            before: null, after: null, context: [], correlationId: null,
            occurredAt: new DateTimeImmutable('2026-09-15T11:00:00Z'),
        ));
        $logger->record(new AuditEntry(
            actor: 'operator:2', action: 'operator.created', resource: 'operator:z',
            before: null, after: null, context: [], correlationId: null,
            occurredAt: new DateTimeImmutable('2026-09-15T12:00:00Z'),
        ));

        $result = $query->search(new AuditSearchCriteria(resource: 'project:a'), AccessScope::platform());

        expect($result->total)->toBe(2)
            ->and($result->items)->toHaveCount(2)
            ->and($result->items[0]->action)->toBe('api_key.revoked')
            ->and($result->items[1]->action)->toBe('api_key.issued');
    }

    #[Test]
    public function it_paginates(): void
    {
        $logger = app(AuditLogger::class);
        $query = app(AuditQuery::class);

        for ($i = 0; $i < 5; $i++) {
            $logger->record(new AuditEntry(
                actor: 'operator:1', action: 'noise', resource: 'project:paginated',
                before: null, after: null, context: [], correlationId: null,
                occurredAt: new DateTimeImmutable(sprintf('2026-09-15T%02d:00:00Z', 10 + $i)),
            ));
        }

        $firstPage = $query->search(new AuditSearchCriteria(resource: 'project:paginated', page: 1, perPage: 2), AccessScope::platform());
        $secondPage = $query->search(new AuditSearchCriteria(resource: 'project:paginated', page: 2, perPage: 2), AccessScope::platform());

        expect($firstPage->total)->toBe(5)
            ->and($firstPage->items)->toHaveCount(2)
            ->and($secondPage->items)->toHaveCount(2)
            ->and($firstPage->items[0]->id)->not->toBe($secondPage->items[0]->id);
    }

    #[Test]
    public function it_filters_by_actor_and_correlation_id(): void
    {
        $logger = app(AuditLogger::class);
        $query = app(AuditQuery::class);

        $logger->record(new AuditEntry(
            actor: 'operator:match', action: 'a', resource: 'r',
            before: null, after: null, context: [], correlationId: 'corr-match',
            occurredAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        ));
        $logger->record(new AuditEntry(
            actor: 'operator:other', action: 'a', resource: 'r',
            before: null, after: null, context: [], correlationId: 'corr-other',
            occurredAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        ));

        $byActor = $query->search(new AuditSearchCriteria(actor: 'operator:match'), AccessScope::platform());
        $byCorrelation = $query->search(new AuditSearchCriteria(correlationId: 'corr-other'), AccessScope::platform());

        expect($byActor->total)->toBe(1)
            ->and($byActor->items[0]->actor)->toBe('operator:match')
            ->and($byCorrelation->total)->toBe(1)
            ->and($byCorrelation->items[0]->correlationId)->toBe('corr-other');
    }

    #[Test]
    public function it_scopes_the_journal_by_organization_and_project_adr_0069(): void
    {
        $logger = app(AuditLogger::class);
        $query = app(AuditQuery::class);

        $orgA = '11111111-1111-4111-8111-111111111111';
        $orgB = '22222222-2222-4222-8222-222222222222';
        $projectA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $projectB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $record = static function (string $action, ?string $organizationId, ?string $projectId) use ($logger): void {
            $logger->record(new AuditEntry(
                actor: 'operator:1', action: $action, resource: 'scope-test',
                before: null, after: null, context: [], correlationId: null,
                occurredAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
                projectId: $projectId,
                organizationId: $organizationId,
            ));
        };

        $record('org-a.updated', $orgA, null);
        $record('project-a.updated', $orgA, $projectA);
        $record('project-a.key-issued', null, $projectA);
        $record('org-b.updated', $orgB, null);
        $record('project-b.updated', $orgB, $projectB);
        $record('platform.login', null, null);

        // Written before the journal carried an attribution: never
        // rewritten, matched on its historical `project:<uuid>` resource.
        $logger->record(new AuditEntry(
            actor: 'operator:1', action: 'project-a.legacy', resource: 'project:'.$projectA,
            before: null, after: null, context: [], correlationId: null,
            occurredAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        ));
        $logger->record(new AuditEntry(
            actor: 'operator:1', action: 'project-b.legacy', resource: 'project:'.$projectB,
            before: null, after: null, context: [], correlationId: null,
            occurredAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
        ));

        $criteria = new AuditSearchCriteria(perPage: 50);

        $tenantA = $query->search($criteria, AccessScope::forOrganization($orgA, [$projectA]));
        $actions = array_map(static fn ($entry): string => $entry->action, $tenantA->items);
        sort($actions);

        // The organization's own entries, its projects' entries and its
        // projects' historical entries — and the total counts nothing else:
        // a tenant never learns how many entries another tenant has.
        expect($actions)->toBe(['org-a.updated', 'project-a.key-issued', 'project-a.legacy', 'project-a.updated'])
            ->and($tenantA->total)->toBe(4);

        // A platform event belongs to nobody: visible to a platform role only.
        expect($query->search($criteria, AccessScope::platform())->total)->toBe(8);

        // An account that belongs to no organization reads nothing at all.
        expect($query->search($criteria, AccessScope::none())->total)->toBe(0);

        // The attribution is readable back on the entry.
        $projectEntry = array_values(array_filter($tenantA->items, static fn ($entry): bool => $entry->action === 'project-a.updated'))[0];
        expect($projectEntry->organizationId)->toBe($orgA)
            ->and($projectEntry->projectId)->toBe($projectA);
    }
}
