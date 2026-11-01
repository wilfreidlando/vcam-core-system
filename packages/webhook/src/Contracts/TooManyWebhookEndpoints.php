<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use RuntimeException;

/**
 * Audit 2026-10-29 #17 / G-12 — a project holds a bounded number of
 * **active** webhook endpoints.
 *
 * Every active endpoint receives every event of the project: an unbounded
 * number of them is an unbounded exfiltration channel, whatever the
 * console's `console-sensitive` throttle does to the *rate* of the
 * registrations. The ceiling is per project, on the model of ADR-0082 §1's
 * ten API keys per project.
 *
 * A deactivated endpoint does not count: it receives nothing, so it does
 * not widen the fan-out — and re-activating it is refused at the ceiling,
 * exactly like a registration.
 *
 * This refusal lives in `Contracts/` (not in `Domain/`) on purpose: the
 * console and the API plane must be able to name it — and translate it
 * into a status — without reaching into this module's internals (G3c),
 * exactly like `Core\Identity\Contracts\LastOrganizationOwner`.
 */
final class TooManyWebhookEndpoints extends RuntimeException
{
    /**
     * The stable, machine-readable code of the refusal — part of the
     * published contract: the `type` of the problem+json answer is built
     * from it, so it must not change with the wording of the message.
     */
    public const string CODE = 'too-many-webhook-endpoints';

    public function __construct(public readonly int $limit)
    {
        parent::__construct(sprintf(
            'This project already holds %d active webhook endpoints, the maximum per project.',
            $limit,
        ));
    }

    public static function forLimit(int $limit): self
    {
        return new self($limit);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * 422 — the request is refused, not the resource: the caller asked
     * for one endpoint too many, and the answer says so (a 409 would
     * suggest a conflicting state of the project itself).
     */
    public function httpStatus(): int
    {
        return 422;
    }
}
