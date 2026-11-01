<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\NotificationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

interface NotificationRepository
{
    /**
     * @throws NotificationAlreadyExists when `(project_id, idempotency_key)` already exists
     */
    public function create(Notification $notification): void;

    public function save(Notification $notification): void;

    public function find(string $id): ?Notification;

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Notification;

    public function findByProviderMessage(string $providerCode, string $providerMessageId): ?Notification;

    /**
     * Newest first, optionally narrowed to one canonical `status`
     * (ADR-0045 — the operator console's project-scoped listing).
     *
     * @param list<string>|null $projectIds when given, only the
     *                                      notifications of those
     *                                      projects are returned
     *                                      (ADR-0069 §2) — the scope
     *                                      filters at the query level
     * @return array{items: list<Notification>, total: int}
     */
    public function findByProject(string $projectId, ?NotificationStatus $status, int $page, int $perPage, ?array $projectIds = null): array;

    /**
     * ADR-0088 §2 — one project's notifications, **oldest first**, ordered
     * by the stable `(created_at, id)` pair and read from an opaque cursor.
     *
     * @param DateTimeImmutable|null $createdFrom inclusive
     * @param DateTimeImmutable|null $createdTo exclusive
     * @return array{items: list<Notification>, nextCursor: ?string}
     *
     * @throws InvalidArgumentException the cursor is not one this listing issued
     */
    public function listByCursor(string $projectId, ?NotificationStatus $status, ?string $channel, ?DateTimeImmutable $createdFrom, ?DateTimeImmutable $createdTo, ?string $cursor, int $limit): array;

    /**
     * ADR-0062 / decision D5 — empties the content and variables (recipient
     * phone number…) of notifications created before `$before`; the row
     * itself (status, attempts, idempotency) is kept. Returns how many rows
     * were redacted.
     */
    public function redactContentCreatedBefore(DateTimeImmutable $before): int;
}
