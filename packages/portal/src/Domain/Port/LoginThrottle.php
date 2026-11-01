<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Port;

use Core\Portal\Domain\Exception\LoginThrottleUnavailable;

/**
 * ADR-0075 §2, ADR-0085 §1 — the hourly budgets of the login by code.
 *
 * **Fails closed**: when the counter cannot be read, the port raises
 * `LoginThrottleUnavailable` and the caller refuses — an outage of the
 * cache must never become an open tap of messages.
 *
 * Every method answers `true` when the action is within its budget (and
 * spends one unit of it), `false` when the budget is spent.
 */
interface LoginThrottle
{
    /**
     * A request for a code: per phone and per address.
     *
     * @throws LoginThrottleUnavailable
     */
    public function admitRequest(string $projectId, string $contactHash, ?string $ip): bool;

    /**
     * A check of a code: per address.
     *
     * @throws LoginThrottleUnavailable
     */
    public function admitVerification(string $projectId, ?string $ip): bool;

    /**
     * A message that is really about to leave: the hourly cap of the project.
     *
     * @throws LoginThrottleUnavailable
     */
    public function admitProjectSend(string $projectId): bool;
}
