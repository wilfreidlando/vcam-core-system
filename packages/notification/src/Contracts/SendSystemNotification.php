<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * ADR-0118 §2 — the voice of the Core.
 *
 * A message the Core writes **as the Core**: it leaves from the platform's
 * own session (the global one named by `notification.system_session`, whose
 * default value stays `default`), never from a project's session, and it is
 * not metered against the recipient's plan.
 *
 * The client's own path — `SendTextNotification` — is untouched: the session
 * named by the caller is the one that sends, and there is no fallback to
 * `default` (ADR-0114 §3).
 */
interface SendSystemNotification
{
    public function send(SendSystemNotificationCommand $command): SystemNotificationResult;
}
