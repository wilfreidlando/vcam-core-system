<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0075 §2 — the customer of a project that owns a contact (a phone or
 * an email), found through the blind index (ADR-0083 §2): nothing is
 * decrypted to be found.
 *
 * Built for the portal's login by code, so it is strict on purpose: an
 * erased customer is never returned, a contact shared by several customers
 * of the project is **ambiguous and returns nothing** (a code sent to a
 * shared number must not open someone else's account), and a project
 * outside the scope finds nothing. The scope is a **required** parameter
 * (ADR-0069 §2).
 */
interface FindCustomerByContact
{
    public function find(FindCustomerByContactCommand $command, AccessScope $scope): ?CustomerView;
}
