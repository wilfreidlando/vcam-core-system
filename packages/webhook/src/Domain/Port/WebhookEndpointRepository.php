<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

use Core\Webhook\Domain\Model\WebhookEndpoint;
use DateTimeImmutable;

interface WebhookEndpointRepository
{
    public function create(WebhookEndpoint $endpoint): void;

    public function save(WebhookEndpoint $endpoint): void;

    public function find(string $id): ?WebhookEndpoint;

    /**
     * Same as `find()`, holding the row until the end of the current
     * transaction: a read-modify-write (secret rotation) never overwrites a
     * concurrent change with stale values.
     */
    public function findForUpdate(string $id): ?WebhookEndpoint;

    /**
     * @return list<WebhookEndpoint>
     */
    public function findActiveForProject(string $projectId): array;

    /**
     * Every endpoint registered for a Project, active or not — the admin
     * console's visibility need. `findActiveForProject()` stays
     * fan-out-only (who should actually receive the next event); an
     * operator managing endpoints needs to see a deactivated one too, to
     * reactivate or edit it.
     *
     * ADR-0069 §2 — this is the query-level restriction of the endpoints
     * listing: the rows are selected by `project_id`, so the account's
     * scope (checked by `ListWebhookEndpointsUseCase` before the call)
     * and the query agree on which project is read.
     *
     * @return list<WebhookEndpoint>
     */
    public function findAllForProject(string $projectId): array;

    /**
     * Audit 2026-10-29 #17 / G-12 — the endpoints that count against the
     * per-project ceiling: the **active** ones. A deactivated endpoint
     * receives nothing, so it does not widen the fan-out and must not
     * block a registration.
     *
     * Call it inside a transaction, after `lockProject()`: the count is
     * only meaningful while the project's registrations are serialised.
     */
    public function countActiveByProject(string $projectId): int;

    /**
     * Serialises the registrations of one project until the end of the
     * current transaction, so two concurrent registrations cannot both
     * pass the ceiling — the same discipline as the API-key cap
     * (ADR-0082 §1).
     */
    public function lockProject(string $projectId): void;

    /**
     * ADR-0082 §5 — erases every previous secret whose overlap has ended.
     *
     * @return int how many were erased
     */
    public function purgeExpiredPreviousSecrets(DateTimeImmutable $now): int;
}
