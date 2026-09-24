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
 * provider closes this: two Projects can each call their session `"main"`
 * and never collide with, let alone reach, each other's. The namespacing
 * is a pure, deterministic string transform; which sessions exist and who
 * owns them is recorded separately, in `notification_channel_sessions`
 * (ADR-0057) — a session with no owner Project is global and keeps its
 * plain name.
 */
final readonly class ScopedSessionRef
{
    private function __construct(
        public string $value,
    ) {}

    /**
     * `_`, not `:` — WAHA rejects any session name outside
     * `[A-Za-z0-9_-]` with a 400 ("Session name can only contain
     * alphanumeric characters, hyphens, and underscores"), so the earlier
     * `<projectId>:<ref>` made every QR/screenshot/pairing-code call fail
     * against a real WAHA. Still unambiguous: a project id is a
     * fixed-length UUID, so the first separator always ends it, and the
     * route already restricts `sessionRef` to `[A-Za-z0-9_-]+`.
     */
    /**
     * The provider-side name of a session by its owner: a Project's own
     * session is namespaced by the Project, a global (platform) session
     * keeps its plain name.
     */
    public static function forOwner(?string $projectId, string $sessionRef): self
    {
        return $projectId === null ? new self($sessionRef) : self::for($projectId, $sessionRef);
    }

    public static function for(string $projectId, string $sessionRef): self
    {
        return new self($projectId.'_'.$sessionRef);
    }
}
