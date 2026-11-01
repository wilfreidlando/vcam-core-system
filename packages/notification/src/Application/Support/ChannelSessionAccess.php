<?php

declare(strict_types=1);

namespace Core\Notification\Application\Support;

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §2 — who may read a channel session.
 *
 * A session belongs to a Project, or to the platform when it has no
 * owner (`project_id` NULL — the global session every Project may send
 * with, ADR-0057). The console reads those sessions under the account's
 * scope; the public API reads the ones of its own Project.
 *
 * Centralised here because the three session ports (status, QR code,
 * screenshot) answer the very same question before calling the provider.
 */
final class ChannelSessionAccess
{
    /**
     * Whether the caller may read the session owned by `$projectId`
     * (`null` = the platform's global session).
     */
    public static function allows(?string $projectId, AccessScope $scope): bool
    {
        if ($projectId === null) {
            // A global session belongs to the platform: an account scoped
            // to its own organizations never sees it — same rule as the
            // console's own "reserved to the platform" check.
            return $scope->allOrganizations;
        }

        return $scope->allowsProject($projectId);
    }
}
