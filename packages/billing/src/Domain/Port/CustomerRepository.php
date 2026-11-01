<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Model\Customer;

/**
 * ADR-0070 §1 — the persistence port of the customers of a project.
 *
 * Pure PHP (G3b): the personal-data boundary of ADR-0083 is an
 * implementation detail of the adapter, never of the domain. The domain
 * holds the clear value; the adapter encrypts it and never hands a
 * ciphertext back.
 */
interface CustomerRepository
{
    /**
     * The customer of a project, by the reference the project knows it by
     * (`customers_project_ref_unique`).
     */
    public function findByExternalRef(string $projectId, string $externalRef): ?Customer;

    /**
     * The customer of its id, whichever project owns it — the caller
     * carries the tenant check (ADR-0070 §Sécurité).
     */
    public function findById(string $id): ?Customer;

    /**
     * ADR-0083 §2 — the customer whose email carries this blind index.
     *
     * The lookup never decrypts a row: the blind index is the searchable
     * form of the address, and it is the form the uniqueness of the
     * address is carried by.
     *
     * @param string $emailBlindIndex 64 lowercase hex characters
     */
    public function findByEmail(string $projectId, string $emailBlindIndex): ?Customer;

    /**
     * Persists a customer, created or updated.
     *
     * @throws CustomerEmailAlreadyTaken another customer of the same project already carries the email
     */
    public function save(Customer $customer): void;
}
