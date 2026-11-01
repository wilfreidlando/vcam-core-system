<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

use DateTimeImmutable;

/**
 * ADR-0081 S-16 — tamper evidence for the audit log. Each complete UTC day
 * is sealed once with SHA-256(previous digest + that day's entries in id
 * order); a sealed day is never re-sealed (that would launder a tampered
 * day). `verify()` recomputes the whole chain.
 */
interface AuditDigests
{
    /**
     * Seals every complete day up to `$upTo` not sealed yet, in order.
     *
     * @return list<AuditDigestView>
     */
    public function sealThrough(DateTimeImmutable $upTo): array;

    public function verify(): AuditVerificationResult;
}
