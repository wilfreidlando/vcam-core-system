<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\Payment;
use Core\Payment\PaymentStatus;
use DateTimeImmutable;
use InvalidArgumentException;

interface PaymentRepository
{
    /**
     * Persists a brand new Payment.
     *
     * @throws \Core\Payment\Domain\Exception\PaymentAlreadyExists on a race
     *                                                             against the `(project_id, idempotency_key)` unique constraint.
     */
    public function create(Payment $payment): void;

    /**
     * Persists a status transition on a Payment that already exists.
     * Race-safe only because the caller resolved the Payment via
     * `findByProviderTransactionRefForUpdate()` first, holding a row lock
     * for the whole transaction — this method itself performs a plain
     * unconditional `UPDATE`, with no status/version predicate of its own.
     */
    public function save(Payment $payment): void;

    public function find(string $id): ?Payment;

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Payment;

    /**
     * Used by inbound webhook handling (ADR-0038 §4/§5): a payment is
     * pinned to exactly one provider at creation, so this lookup is
     * always direct — never a search across every registered adapter.
     */
    public function findByProviderTransactionRef(string $providerCode, string $providerTransactionRef): ?Payment;

    /**
     * Same lookup as `findByProviderTransactionRef()`, but takes a
     * row-level lock (`SELECT ... FOR UPDATE`) for the remainder of the
     * caller's transaction. `HandlePaymentStatusChangedUseCase` is the
     * only writer of a Payment's status and is reached from two
     * concurrent entry points — an inbound webhook and the reconciliation
     * job (ADR-0044) — so without this lock two transactions can both
     * read the same pre-transition row, both pass the terminal-status
     * guard, and both write: a lost update, a double wallet credit
     * attempt, or an outbox event published twice for one transition.
     * The lock forces the second transaction to block until the first
     * commits, at which point it re-reads the now-committed row and its
     * terminal-status guard correctly short-circuits.
     */
    public function findByProviderTransactionRefForUpdate(string $providerCode, string $providerTransactionRef): ?Payment;

    /**
     * Non-terminal payments (`pending`/`processing`) neither changed nor
     * checked since before `$threshold`, created at or after
     * `$createdAfter` — candidates for reconciliation (ADR-0044, ADR-0067):
     * a missed webhook, not necessarily a failure. Least recently looked
     * at first (`GREATEST(updated_at, reconciled_at)`), so that payments
     * the provider keeps answering `pending` for take their turn instead
     * of starving newer ones.
     *
     * @param list<string>|null $projectIds when given, only the payments
     *                                      of those projects are
     *                                      returned (ADR-0069 §2)
     * @return list<Payment>
     */
    public function findStuckSince(DateTimeImmutable $threshold, int $limit, DateTimeImmutable $createdAfter, ?array $projectIds = null): array;

    /**
     * Records that the provider was just asked about this payment
     * (`reconciled_at`, ADR-0067), whatever the answer — without touching
     * `updated_at`, which keeps meaning "the payment last changed".
     */
    public function markReconciliationChecked(string $paymentId, DateTimeImmutable $checkedAt): void;

    /**
     * Newest first, optionally narrowed to one `projectId` and/or one
     * canonical `status` (ADR-0045 — the operator console's listing).
     * `$projectId` is nullable — a null value lists across every
     * Project, reachable only from the trusted operator-authenticated
     * admin plane (never from a Project/API-key caller, which has no
     * access to this port at all), same nullable-filter convention
     * already used by `ListProjectsCommand::$organizationId` and
     * `ListDeadWebhookDeliveriesCommand::$projectId`.
     *
     * @param list<string>|null $projectIds when given, only the payments
     *                                      of those projects are
     *                                      returned (ADR-0069 §2)
     * @return array{items: list<Payment>, total: int}
     */
    public function findByProject(?string $projectId, ?PaymentStatus $status, int $page, int $perPage, ?array $projectIds = null): array;

    /**
     * ADR-0088 §2 — one project's payments, **oldest first**, ordered by
     * the stable `(created_at, id)` pair and read from an opaque cursor.
     * A row written while the listing is read is appended after the ones
     * already read: nothing is repeated, nothing older is skipped.
     *
     * @param DateTimeImmutable|null $createdFrom inclusive
     * @param DateTimeImmutable|null $createdTo exclusive
     * @return array{items: list<Payment>, nextCursor: ?string}
     *
     * @throws InvalidArgumentException the cursor is not one this listing issued
     */
    public function listByCursor(string $projectId, ?PaymentStatus $status, ?DateTimeImmutable $createdFrom, ?DateTimeImmutable $createdTo, ?string $cursor, int $limit): array;

    /**
     * A pure count of the same candidate set `findStuckSince()` would
     * hydrate — for a dashboard stat that must never trigger a real
     * reconciliation run (review finding, Lot 6 follow-up: the admin
     * console used to call `ReconcileStuckPayments::reconcile()`, a
     * live-provider-calling, state-mutating action, just to populate a
     * "count" widget).
     *
     * @param list<string>|null $projectIds when given, only the payments
     *                                      of those projects are counted
     *                                      (ADR-0069 §2). It is applied
     *                                      in the query, so the payments
     *                                      of another tenant are never
     *                                      counted.
     */
    public function countStuckSince(DateTimeImmutable $threshold, DateTimeImmutable $createdAfter, ?array $projectIds = null): int;

    /**
     * Counts over a time window — the admin dashboard's own read,
     * distinct from `findByProject()`'s project-scoped listing (ADR-0045
     * deliberately requires a `projectId` there; this one stays a pure
     * count, never row-level data).
     *
     * @param list<string>|null $projectIds when given, only the payments
     *                                      of those projects are counted
     *                                      (ADR-0069 §2). It is applied
     *                                      in the aggregation query, so
     *                                      the payments of another tenant
     *                                      are never counted.
     * @return array{total: int, succeeded: int}
     */
    public function countRecentByStatus(DateTimeImmutable $since, ?array $projectIds = null): array;

    /**
     * ADR-0078 §2 — succeeded payments changed since `$since`, oldest
     * first, for the hourly invariant check.
     *
     * @return list<Payment>
     */
    public function findSucceededUpdatedSince(DateTimeImmutable $since, int $limit): array;

    /**
     * @param list<string> $ids
     * @return list<Payment>
     */
    public function findByIds(array $ids): array;

    /**
     * Projects with at least one payment changed since `$since`.
     *
     * @return list<string>
     */
    public function projectsWithActivitySince(DateTimeImmutable $since): array;
}
