<?php

declare(strict_types=1);

namespace Core\Audit\Tests\Integration\Infrastructure\Persistence\Eloquent;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Audit\Contracts\AuditQuery;
use Core\Audit\Contracts\AuditSearchCriteria;
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

        $result = $query->search(new AuditSearchCriteria(resource: 'project:a'));

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

        $firstPage = $query->search(new AuditSearchCriteria(resource: 'project:paginated', page: 1, perPage: 2));
        $secondPage = $query->search(new AuditSearchCriteria(resource: 'project:paginated', page: 2, perPage: 2));

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

        $byActor = $query->search(new AuditSearchCriteria(actor: 'operator:match'));
        $byCorrelation = $query->search(new AuditSearchCriteria(correlationId: 'corr-other'));

        expect($byActor->total)->toBe(1)
            ->and($byActor->items[0]->actor)->toBe('operator:match')
            ->and($byCorrelation->total)->toBe(1)
            ->and($byCorrelation->items[0]->correlationId)->toBe('corr-other');
    }
}
