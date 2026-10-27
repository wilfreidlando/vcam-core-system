<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Application\Port\CustomerDirectory;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\CustomerVersionConflict;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\CustomerStatus;
use Core\Billing\Infrastructure\Persistence\Cursor;
use Core\Support\Privacy\BlindIndex;
use Core\Support\Privacy\PersonalDataCipher;
use Core\Support\Privacy\PersonalDataKind;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * ADR-0070 §1 / ADR-0083 §2 — the customers of a project.
 *
 * **The PII boundary is here.** The domain holds the email and the phone
 * in clear (it checks their format); this adapter is the only place
 * where they are encrypted, and the only place where they are read back:
 * the domain never sees a ciphertext, and the disk never sees a clear
 * value.
 *
 * The value is **normalised before it is encrypted and before its blind
 * index is computed** (`PersonalDataKind::normalize()`): `Ali@Example.cm`
 * and `ali@example.cm` are the same address, so they are the same
 * ciphertext and the same index — and the uniqueness of an address
 * holds whichever spelling the project used.
 */
final class EloquentCustomerRepository implements CustomerDirectory
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly PersonalDataCipher $cipher,
        private readonly BlindIndex $blindIndex,
    ) {}

    public function findByExternalRef(string $projectId, string $externalRef): ?Customer
    {
        $record = CustomerRecord::query()
            ->where('project_id', $projectId)
            ->where('external_ref', $externalRef)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findById(string $id): ?Customer
    {
        $record = CustomerRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByEmail(string $projectId, string $emailBlindIndex): ?Customer
    {
        // The blind index is the searchable form of the address: the row
        // is never decrypted to be found (ADR-0083 §2).
        $record = CustomerRecord::query()
            ->where('project_id', $projectId)
            ->where('email_bidx', $emailBlindIndex)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function save(Customer $customer): void
    {
        try {
            // SAVEPOINT (nested transaction) — always runs inside the
            // use case's outer transaction. Without this, a caught
            // violation poisons the whole outer PostgreSQL transaction
            // (the same comment as EloquentPaymentRepository::create()).
            DB::transaction(function () use ($customer): void {
                $attributes = $this->toAttributes($customer);

                // `toBase()` — `exists()` is forwarded by Eloquent's
                // builder to the query builder rather than declared on
                // it, which trips a Larastan false positive (the same
                // workaround as EloquentPaymentRepository).
                if (CustomerRecord::query()->whereKey($customer->id)->toBase()->exists()) {
                    // ADR-0070 §2 — the optimistic lock is **in the
                    // write**, not only in the use case: the row carries
                    // the version it had before the change, and a write
                    // that touches no row means someone else moved it
                    // in between. Two declarations racing on the same
                    // reference must not overwrite each other silently.
                    $updated = CustomerRecord::query()
                        ->whereKey($customer->id)
                        ->where('version', $customer->version - 1)
                        ->update($attributes);

                    if ($updated === 0) {
                        $stored = CustomerRecord::query()->find($customer->id);
                        $currentVersion = $stored === null ? $customer->version : $stored->version;

                        throw CustomerVersionConflict::forCustomer(
                            $customer->externalRef,
                            $customer->version - 1,
                            $currentVersion,
                        );
                    }
                } else {
                    CustomerRecord::query()->create(
                        ['id' => $customer->id, 'created_at' => $customer->createdAt] + $attributes,
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            // The only unique index a customer can break besides the
            // reference one is the email one: the blind index carries the
            // uniqueness of the address per project (ADR-0083 §2).
            throw CustomerEmailAlreadyTaken::inProject($customer->projectId);
        }
    }

    /**
     * ADR-0088 §2 — the customers of a project, page after page.
     *
     * The cursor is the `(created_at, id)` pair of the last row of the
     * previous page: the listing never skips nor repeats a row, whatever
     * is written while it is read.
     *
     * The search matches the two fields the project knows a customer by —
     * its reference and its display name. The email is not among them: it
     * is encrypted and has no literal form to be matched against
     * (ADR-0083 §2).
     *
     * @return array{items: list<Customer>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $search,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // `where`/`orderBy`/`limit` are forwarded by Eloquent's builder to
        // the query builder rather than declared on it; building the whole
        // query on `toBase()` avoids the Larastan false positive on those
        // forwarded calls, and `CustomerRecord::hydrate()` restores the
        // model casts afterwards (the same workaround as
        // EloquentPaymentRepository).
        $base = CustomerRecord::query()->toBase()->where('project_id', $projectId);

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';

            $base->where(static function (QueryBuilder $q) use ($needle): void {
                $q->where('external_ref', 'like', $needle)
                    ->orWhere('display_name', 'like', $needle);
            });
        }

        if ($cursor !== null) {
            [$createdAt, $id] = Cursor::decode($cursor);

            $base->where(static function (QueryBuilder $q) use ($createdAt, $id): void {
                $q->where('created_at', '>', $createdAt)
                    ->orWhere(static function (QueryBuilder $q2) use ($createdAt, $id): void {
                        $q2->where('created_at', '=', $createdAt)->where('id', '>', $id);
                    });
            });
        }

        $rows = $base->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()
            ->map(static fn (object $row): array => (array) $row)->all();

        $records = CustomerRecord::hydrate($rows);

        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);

        $items = [];
        foreach ($records as $record) {
            $items[] = $this->toDomain($record);
        }

        $last = $records->last();

        return [
            'items' => $items,
            'nextCursor' => $hasMore && $last !== null
                ? Cursor::encode($last->created_at->format('Y-m-d H:i:s.uP'), $last->id)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(Customer $customer): array
    {
        $email = $customer->email === null ? null : PersonalDataKind::Email->normalize($customer->email);
        $phone = $customer->phone === null ? null : PersonalDataKind::Phone->normalize($customer->phone);

        return [
            'project_id' => $customer->projectId,
            'external_ref' => $customer->externalRef,
            'display_name' => $customer->displayName,
            'email_ciphertext' => $email === null ? null : $this->cipher->encrypt($email),
            'email_bidx' => $email === null ? null : $this->blindIndex->of($email, PersonalDataKind::Email),
            'phone_ciphertext' => $phone === null ? null : $this->cipher->encrypt($phone),
            'phone_bidx' => $phone === null ? null : $this->blindIndex->of($phone, PersonalDataKind::Phone),
            'locale' => $customer->locale,
            'metadata' => $this->metadata($customer->metadata),
            'status' => $customer->status->value,
            'version' => $customer->version,
            'erased_at' => $customer->erasedAt,
            // The instant of the last change is the domain's, never the
            // clock of the database (ADR-0070 §2).
            'updated_at' => $customer->updatedAt,
        ];
    }

    /**
     * The metadata of a customer is a **map** (ADR-0070 §1) — and the
     * column refuses anything else (`customers_metadata_object_check`).
     * An erased customer carries no metadata at all, and an empty PHP
     * array would be stored as a JSON array: it is written as an empty
     * object instead.
     *
     * @param array<string, mixed> $metadata
     */
    private function metadata(array $metadata): string
    {
        return (string) json_encode($metadata === [] ? new stdClass : $metadata);
    }

    /**
     * The metadata as a map — the column holds a JSON **object** (ADR-0070 §1).
     *
     * @return array<string, mixed>
     */
    private function metadataOf(string $json): array
    {
        $decoded = json_decode($json, true);
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($decoded) ? $decoded : [];

        return $metadata;
    }

    private function toDomain(CustomerRecord $record): Customer
    {
        return new Customer(
            id: $record->id,
            projectId: $record->project_id,
            externalRef: $record->external_ref,
            displayName: $record->display_name,
            email: $record->email_ciphertext === null ? null : $this->cipher->decrypt($record->email_ciphertext),
            phone: $record->phone_ciphertext === null ? null : $this->cipher->decrypt($record->phone_ciphertext),
            locale: $record->locale,
            metadata: $this->metadataOf($record->metadata),
            status: CustomerStatus::from($record->status),
            version: $record->version,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
            erasedAt: $record->erased_at === null ? null : DateTimeImmutable::createFromInterface($record->erased_at),
        );
    }
}
