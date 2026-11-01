<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Throwable;

/**
 * ADR-0069 — a refusal raised by the organization-membership rules.
 *
 * These refusals live in `Contracts/` (not in `Domain/`) on purpose: the
 * operator console must be able to translate them into an HTTP status,
 * and G3c forbids the console from naming another module's Domain
 * classes. `notification` already publishes its refusals the same way
 * (`Contracts\ChannelSessionNotFound`, `Contracts\MissingChannelSession`).
 *
 * The Domain layer never throws them: the models state what is true
 * (`isUsable()`, `role`), the Application layer decides what is refused
 * and raises one of these.
 */
interface OrganizationRefusal extends Throwable
{
    /**
     * The stable, machine-readable code of the refusal — `last-owner`
     * for the last owner of an organization, for instance. It is part of
     * the published contract: the console's problem+json `type` is built
     * from it, so it must not change with the wording of a message.
     */
    public function errorCode(): string;

    /**
     * The HTTP status the console translates this refusal into (409 for
     * a conflict, 403 for a forbidden action, 404 for a resource the
     * caller may not see, 422 for an invalid input).
     *
     * It lives here rather than in the console so the JSON plane and the
     * web console cannot drift apart on what a refusal means.
     */
    public function httpStatus(): int;
}
