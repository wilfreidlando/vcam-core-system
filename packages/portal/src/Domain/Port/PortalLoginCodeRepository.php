<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Port;

use Core\Portal\Domain\Model\PortalLoginCode;
use DateTimeImmutable;

/**
 * ADR-0075 §2 — only hashes cross this port. The two writes that decide
 * who gets in (`registerAttempt`, `consume`) are conditional UPDATEs: the
 * database arbitrates a race, not a read followed by a write.
 */
interface PortalLoginCodeRepository
{
    /**
     * Stores a new code and retires the live ones of the same phone, so
     * that at most one code can be right at a time.
     */
    public function issue(PortalLoginCode $code, ?string $createdByIp, DateTimeImmutable $now): void;

    /**
     * When the last code for this phone was issued (live or not) — the
     * resend limit reads it.
     */
    public function lastIssuedAt(string $projectId, string $contactHash): ?DateTimeImmutable;

    /**
     * The code that may still be right for this phone: the newest one, not
     * consumed, not expired, with attempts left.
     */
    public function findLive(string $projectId, string $contactHash, DateTimeImmutable $now): ?PortalLoginCode;

    /**
     * Spends one attempt, atomically: false when the code is gone, spent,
     * expired or out of attempts. Called *before* the comparison, so
     * concurrent guesses cannot exceed the limit.
     */
    public function registerAttempt(string $id, DateTimeImmutable $now): bool;

    /**
     * Consumes the code **once**: true for the single caller whose
     * conditional UPDATE found it unconsumed.
     */
    public function consume(string $id, DateTimeImmutable $now): bool;
}
