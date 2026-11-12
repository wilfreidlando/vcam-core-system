<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\CustomerVersionConflict;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\CustomerStatus;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0070 §2 — the project declares its customers:
 * `PUT /v1/customers/{external_ref}` creates or updates, **idempotent by
 * construction** — the reference is the correlation key, so the same
 * declaration twice leaves one row.
 *
 * Two guards come with it:
 *
 * - the **version** (`If-Match`): the caller sends the version it last
 *   read and the write is refused when the stored one has moved on
 *   (`412 version-conflict`);
 * - the **email**: it is unique per project — the check compares the
 *   normalised address (ADR-0070 §4).
 *
 * The personal values are held in clear here, and they are **stored** in
 * clear (ADR-0123): the Core keeps no key of its own for them. What it
 * still guards is their exposure (ADR-0083 §3) and their erasure
 * (ADR-0083 §6).
 */
final readonly class UpsertCustomerUseCase implements UpsertCustomer
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private CustomerRepository $customers,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function upsert(UpsertCustomerCommand $command): CustomerView
    {
        return $this->unitOfWork->transactional(function () use ($command): CustomerView {
            $now = $this->clock->now();

            $existing = $this->customers->findByExternalRef($command->projectId, $command->externalRef);

            $email = self::normalizeEmail($command->email);
            $phone = self::normalizePhone($command->phone);

            $this->assertEmailIsFree($command->projectId, $email, $existing?->id);

            if ($existing === null) {
                $customer = new Customer(
                    id: $this->identifier->generate(),
                    projectId: $command->projectId,
                    externalRef: $command->externalRef,
                    displayName: $command->displayName,
                    email: $email,
                    phone: $phone,
                    locale: $command->locale,
                    metadata: $command->metadata,
                    status: CustomerStatus::Active,
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );

                $this->customers->save($customer);

                $this->outbox->append(new DomainEvent(
                    $this->identifier->generate(),
                    $command->projectId,
                    'customer.created',
                    $this->payload($customer),
                    $command->correlationId,
                    $now,
                ));

                return CustomerViews::from($customer);
            }

            // ADR-0070 §1 / ADR-0083 §3 — the erasure is **final**: the
            // identity is gone and the row only keeps the history. An
            // erased customer cannot be written to again — an upsert
            // would silently put a name, an address and a phone number
            // back on a line that was emptied on purpose.
            if ($existing->isErased()) {
                throw InvalidCustomerTransition::becauseNotAllowed($existing->status->value, 'a new declaration');
            }

            // ADR-0070 §2 — the optimistic lock of the customer.
            if ($command->expectedVersion !== null && $command->expectedVersion !== $existing->version) {
                throw CustomerVersionConflict::forCustomer(
                    $command->externalRef,
                    $command->expectedVersion,
                    $existing->version,
                );
            }

            $existing->updateProfile(
                $command->displayName,
                $email,
                $phone,
                $command->locale,
                $command->metadata,
                $now,
            );

            // The row moved: the ETag follows it, or a caller holding an
            // old version would not see the change it has just made.
            $existing->version++;

            $this->customers->save($existing);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'customer.updated',
                $this->payload($existing),
                $command->correlationId,
                $now,
            ));

            return CustomerViews::from($existing);
        });
    }

    /**
     * ADR-0070 §1 — the email is unique per project. The comparison is
     * made on the normalised address (ADR-0070 §4), which is what the row
     * carries (ADR-0123).
     */
    private function assertEmailIsFree(string $projectId, ?string $email, ?string $customerId): void
    {
        if ($email === null) {
            return;
        }

        $owner = $this->customers->findByEmail($projectId, $email);

        if ($owner !== null && $owner->id !== $customerId) {
            throw CustomerEmailAlreadyTaken::inProject($projectId);
        }
    }

    /**
     * ADR-0070 §3 — the payload of the events of a customer: no personal
     * data, only the project's own reference.
     *
     * @return array<string, mixed>
     */
    private function payload(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'external_ref' => $customer->externalRef,
            'status' => $customer->status->value,
            'version' => $customer->version,
        ];
    }

    /**
     * ADR-0070 §4 — the email is normalised (lowercase, spaces removed)
     * before it is stored and before it is looked up, so that
     * `Ali@Example.CM ` and `ali@example.cm` are the same address.
     */
    private static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = str_replace(' ', '', mb_strtolower(trim($email)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * The phone is kept as declared, minus its surrounding spaces: the
     * strict E.164 shape is the edge's business (ADR-0070 §4), the Core
     * only refuses to keep a value that carries nothing at all.
     */
    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = trim($phone);

        return $normalized === '' ? null : $normalized;
    }
}
