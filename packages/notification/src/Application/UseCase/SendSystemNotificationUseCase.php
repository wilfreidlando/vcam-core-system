<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Contracts\SendSystemNotification;
use Core\Notification\Contracts\SendSystemNotificationCommand;
use Core\Notification\Contracts\SystemNotificationResult;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Psr\Log\LoggerInterface;

/**
 * ADR-0118 §2 — the voice of the Core.
 *
 * Three rules, and they are the whole point of this use case:
 *
 *  - the session is the **global** one named by `notification.system_session`
 *    (default value: `default`), resolved by **exact ownership** — a
 *    project's own session of the same name never takes its place;
 *  - the session is never created: when the platform has none, nothing is
 *    written and nothing is sent, and the omission says why;
 *  - the message is not metered: the Core informs a customer of their own
 *    commercial state, that is not a service the customer's plan buys
 *    (ADR-0118 §5).
 *
 * The client's own path — `SendTextNotification` — is untouched: the session
 * the caller names is the one that sends, with no fallback to `default`
 * (ADR-0114 §3).
 */
final readonly class SendSystemNotificationUseCase implements SendSystemNotification
{
    public function __construct(
        private NotificationSendCascade $cascade,
        private ChannelSessionRepository $sessions,
        private LoggerInterface $logger,
        private string $platformSessionRef,
    ) {}

    public function send(SendSystemNotificationCommand $command): SystemNotificationResult
    {
        // Exact owner match, `null` being the global session: never
        // `findUsableFor`, which would let a project's session of the same
        // name answer for the platform.
        $session = $this->sessions->find(null, $command->channel->value, $this->platformSessionRef);

        if ($session === null) {
            $this->logger->warning('notification.system.session_missing', [
                'project_id' => $command->projectId,
                'channel' => $command->channel->value,
                'session_ref' => $this->platformSessionRef,
                'reason' => 'platform-session-missing',
            ]);

            return SystemNotificationResult::notSent('platform-session-missing');
        }

        $variables = $command->variables;
        $variables['phone_number'] = $command->phoneNumber;

        $view = $this->cascade->send(
            projectId: $command->projectId,
            channel: $command->channel,
            toPartyRef: $command->toPartyRef,
            variables: $variables,
            body: ['type' => 'text', 'text' => $command->text],
            contentType: 'text',
            idempotencyKey: $command->idempotencyKey,
            session: $session,
            metered: false,
        );

        return SystemNotificationResult::of($view);
    }
}
