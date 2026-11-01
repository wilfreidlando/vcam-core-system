<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0075 §2 — a login code in flight.
 *
 * Six digits, valid ten minutes, five attempts, one resend a minute. The
 * code itself is never kept: only its keyed hash, bound to the project and
 * to the phone it was sent to. The phone is not kept either — `contactHash`
 * is the keyed hash that finds the row.
 */
final readonly class PortalLoginCode
{
    public const LENGTH = 6;

    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function __construct(
        public string $id,
        public string $projectId,
        public string $customerId,
        public string $customerRef,
        public string $contactHash,
        public string $codeHash,
        public int $attempts,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * A well-formed code: exactly six digits, nothing else.
     */
    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^[0-9]{'.self::LENGTH.'}\z/', $code) === 1;
    }

    /**
     * A uniformly random code from the system CSPRNG, zero-padded.
     */
    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }
}
