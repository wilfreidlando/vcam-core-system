<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Model\Customer;

/**
 * ADR-0070 §1 — the persistence port of the customers of a project.
 *
 * Pure PHP (G3b): the domain holds the client's data **as it is** — the
 * Core stores it in clear (ADR-0123) — and the rules that decide what
 * leaves it (ADR-0083 §3) belong to the adapter, never to the domain.
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
     * The customer of a project whose email is this one.
     *
     * The address is normalised before it is stored and before it is
     * looked up (ADR-0070 §4) : `Ali@Example.cm` and `ali@example.cm`
     * are the same address, and the uniqueness of an address per project
     * is carried by it (ADR-0123).
     */
    public function findByEmail(string $projectId, string $email): ?Customer;

    /**
     * Persists a customer, created or updated.
     *
     * @throws CustomerEmailAlreadyTaken another customer of the same project already carries the email
     */
    public function save(Customer $customer): void;
}
