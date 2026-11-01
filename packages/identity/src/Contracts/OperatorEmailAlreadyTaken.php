<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §1 — **one console account per mailbox**.
 *
 * The comparison is case-insensitive: `Foo@example.com` and
 * `foo@example.com` are the same mailbox, and every mailbox belongs to
 * one person. Two accounts for it would mean two credentials for one
 * person — and, for the invitation flow, an invitation addressed to one
 * casing landing on a brand-new account instead of the existing one.
 *
 * The message never repeats the address: it travels to whoever holds
 * `operators:write` (a platform role), but a refusal that quotes its
 * input has no reason to.
 *
 * The console answers **409**.
 */
final class OperatorEmailAlreadyTaken extends RuntimeException
{
    public const string CODE = 'operator-email-already-taken';

    public function __construct(public readonly string $email)
    {
        parent::__construct('A console account already exists for this email address.');
    }

    public static function forEmail(string $email): self
    {
        return new self($email);
    }
}
