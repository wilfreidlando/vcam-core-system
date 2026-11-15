<?php

declare(strict_types=1);

namespace App\Http\View;

use Core\Notification\Contracts\ListChannelSessions;
use Core\Notification\Contracts\ListChannelSessionsCommand;
use Core\Notification\Contracts\NotificationChannel;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Support\Access\AccessScope;

/**
 * ADR-0125 §4, ADR-0100, ADR-0057 — the login by code of a project, as
 * the operator is told about it.
 *
 * When the project offers the login by code, the customer's sheet shows
 * **two** things: the address the customer is given, and **the WhatsApp
 * session the code leaves through**. The second one matters: it is the
 * project's own session, or one of the platform's global sessions that
 * every project may send with (ADR-0057). The operator does not have to
 * know that "global" exists — the screen says what will happen.
 *
 * The block lives in the app shell (like `CustomerLabels` or
 * `AuditLabels`) because it is **wording**, not business: the modules
 * that answer the question are the portal's settings and the
 * notification's sessions.
 *
 * It is computed for the **whole sheet**, not only for the response that
 * opens the space: the operator must be able to read the address and the
 * sending session on the sheet he lands on, before he gives anything to
 * anybody.
 */
final readonly class PortalDirectLogin
{
    /** The channel the login code leaves through (ADR-0100). */
    private const CHANNEL = 'whatsapp';

    public function __construct(
        private ManagePortalSettings $portalSettings,
        private ListChannelSessions $channelSessions,
    ) {}

    /**
     * The block, or null when the project does not offer the login by
     * code.
     *
     * @return array{url: string, session: string, owner: string, owner_label: string}|null
     */
    public function for(string $projectId, AccessScope $scope): ?array
    {
        $settings = $this->portalSettings->get($projectId, $scope);

        if ($settings === null || ! $settings->directLoginEnabled) {
            return null;
        }

        $session = $settings->directLoginSession;

        if ($session === null || $session === '') {
            return null;
        }

        $owner = $this->sessionOwner($projectId, $session, $scope);

        return [
            'url' => $this->portalBaseUrl().'/portal/'.$projectId.'/login',
            'session' => $session,
            'owner' => $owner,
            'owner_label' => $this->ownerLabel($owner, $scope),
        ];
    }

    /**
     * Whether the sending session is the project's own, or one of the
     * platform's global sessions.
     */
    private function sessionOwner(string $projectId, string $sessionRef, AccessScope $scope): string
    {
        $own = $this->channelSessions->list(
            new ListChannelSessionsCommand($projectId, NotificationChannel::fromString(self::CHANNEL)),
            $scope,
        );

        foreach ($own as $session) {
            if ($session->sessionRef === $sessionRef) {
                return 'project';
            }
        }

        // The platform's own sessions belong to no project: only a
        // platform account reads them (ADR-0069 §2).
        $global = $this->channelSessions->list(
            new ListChannelSessionsCommand(null, NotificationChannel::fromString(self::CHANNEL)),
            $scope,
        );

        foreach ($global as $session) {
            if ($session->sessionRef === $sessionRef) {
                return 'global';
            }
        }

        return 'unknown';
    }

    /**
     * What the operator is told about the sending session.
     */
    private function ownerLabel(string $owner, AccessScope $scope): string
    {
        return match ($owner) {
            'project' => 'c\'est la session d\'envoi de ce projet.',
            'global' => 'c\'est une session globale : tous les projets peuvent s\'en servir.',
            // An organization account cannot read the platform's sessions:
            // it says what it knows, without denying what it cannot see.
            default => $scope->allOrganizations
                ? 'ce nom n\'est enregistré ni pour ce projet ni parmi les sessions de la plateforme : le code ne partira pas.'
                : 'ce nom n\'est pas enregistré pour ce projet : si une session globale porte ce nom, c\'est elle qui sera utilisée.',
        };
    }

    /**
     * The address of the portal, the one the module itself uses.
     */
    private function portalBaseUrl(): string
    {
        $base = config('portal.base_url', config('app.url'));

        return rtrim(is_string($base) ? $base : '', '/');
    }
}
