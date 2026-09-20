<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Model;

/**
 * Security review finding: `sessionRef` used to be forwarded to the
 * provider (WAHA) exactly as the caller supplied it, with `projectId`
 * used only to resolve which provider config to route to — nothing
 * stopped Project A from supplying Project B's `sessionRef` and
 * hijacking its WhatsApp session (QR code, screenshot, pairing to a
 * different phone number), since today's single shared WAHA instance
 * has no other ownership boundary between Projects.
 *
 * Namespacing the ref by `projectId` before it ever reaches the
 * provider closes this without persisting anything about the session
 * (ADR-0042's "Core persists nothing about a session" stays true — this
 * is a pure, deterministic string transform, not a stored ownership
 * record): two Projects can each call their session `"main"` and never
 * collide with, let alone reach, each other's.
 */
final readonly class ScopedSessionRef
{
    private function __construct(
        public string $value,
    ) {}

    public static function for(string $projectId, string $sessionRef): self
    {
        return new self($projectId.':'.$sessionRef);
    }
}
