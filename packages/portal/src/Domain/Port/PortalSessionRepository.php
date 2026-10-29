<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Port;

use Core\Portal\Domain\Model\PortalSession;
use DateTimeImmutable;

/**
 * ADR-0075 §1 — only hashes cross this port: the link and the cookie are
 * never stored in the clear.
 */
interface PortalSessionRepository
{
    public function create(PortalSession $session, string $tokenHash, ?string $createdByIp): void;

    /**
     * Consumes the link **once**, atomically: the row is claimed by a
     * conditional UPDATE (not consumed yet, not expired), so two browsers
     * racing on the same link cannot both win.
     */
    public function exchange(string $tokenHash, string $sessionHash, DateTimeImmutable $now, DateTimeImmutable $sessionExpiresAt): ?PortalSession;

    /**
     * The live session behind a cookie: not revoked, not past its absolute
     * end, seen since `$idleCutoff`.
     */
    public function findLive(string $sessionHash, DateTimeImmutable $now, DateTimeImmutable $idleCutoff): ?PortalSession;

    public function touch(string $id, DateTimeImmutable $now): void;

    public function revoke(string $id, DateTimeImmutable $now): void;
}
