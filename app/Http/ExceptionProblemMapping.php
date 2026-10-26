<?php

declare(strict_types=1);

namespace App\Http;

use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InvalidOrganizationRole;
use Core\Identity\Contracts\InvitationAccountPasswordInvalid;
use Core\Identity\Contracts\InvitationNotUsable;
use Core\Identity\Contracts\InvitationPasswordTooWeak;
use Core\Identity\Contracts\LastOrganizationOwner;
use Core\Identity\Contracts\OperatorAlreadyExists;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OrganizationInvitationAlreadyExists;
use Core\Identity\Contracts\OrganizationInvitationNotFound;
use Core\Identity\Contracts\OrganizationMemberManagementForbidden;
use Core\Identity\Contracts\OrganizationMemberNotFound;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationPrivilegeEscalation;
use Core\Identity\Contracts\PlatformRoleAssignmentForbidden;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\MfaAlreadyEnrolled;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\MissingChannelSession;
use Core\Notification\Contracts\MissingRecipientPhoneNumber;
use Core\Notification\Contracts\NoProviderForNotificationChannelException;
use Core\Notification\Domain\Exception\NotificationNotFound;
use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Payment\Domain\Exception\PaymentNotSimulable;
use Core\Payment\Domain\Exception\ProviderApplicationNotFound;
use Core\Payment\Domain\Exception\UnsupportedCurrency;
use Core\Support\Http\ProblemDetails;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\InvalidWalletOperation;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Exception\WalletProjectMismatch;
use Core\Webhook\Domain\Exception\InvalidWebhookSecretRotation;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotDead;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Exception\WebhookEndpointInactive;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The single source of truth mapping a domain exception to its RFC 7807
 * problem+json shape (`type`/`title`/`status`) — consumed by BOTH
 * `bootstrap/app.php`'s actual exception handler and
 * `App\OpenApi\ExceptionProblemResponseExtension` (a Scramble extension),
 * so `/docs/api`/`/docs/admin` document the SAME error shapes the API
 * genuinely returns, not just a generic 422/404 for everything (review
 * finding: Scramble's static analysis has no way to see an exception
 * thrown deep in a use case and centrally mapped in the exception
 * handler — before this, every one of these 22 distinct business errors
 * was invisible in the docs beyond "422 → ValidationException" or
 * "404 → generic not-found").
 *
 * Every entry here maps its exception's own `getMessage()` as the
 * `detail` field, unmodified. An exception whose message isn't
 * appropriate as a public detail (Laravel's own `AuthenticationException`,
 * whose message is an internal guard name, not something a caller should
 * see) is NOT listed here — it stays a special case in
 * `bootstrap/app.php`, with its own fixed detail string, same as before.
 */
final class ExceptionProblemMapping
{
    /**
     * @var array<class-string<Throwable>, array{type: string, title: string, status: int}>
     */
    private const MAP = [
        WalletNotFound::class => ['type' => 'wallet-not-found', 'title' => 'Wallet not found', 'status' => 404],
        // ADR-0065 — same answer as not found: never reveal a wallet outside the caller's reach.
        WalletProjectMismatch::class => ['type' => 'wallet-not-found', 'title' => 'Wallet not found', 'status' => 404],
        InsufficientFunds::class => ['type' => 'insufficient-funds', 'title' => 'Insufficient funds', 'status' => 409],
        InvalidWalletOperation::class => ['type' => 'invalid-wallet-operation', 'title' => 'The wallet operation is invalid', 'status' => 422],
        PaymentNotFound::class => ['type' => 'payment-not-found', 'title' => 'Payment not found', 'status' => 404],
        PaymentNotSimulable::class => ['type' => 'payment-not-simulable', 'title' => 'This payment cannot be simulated', 'status' => 409],
        NoProviderForChannelException::class => ['type' => 'no-provider-for-channel', 'title' => 'No provider is configured for this channel', 'status' => 422],
        ProviderApplicationNotFound::class => ['type' => 'no-provider-application', 'title' => 'No provider application is registered for this project', 'status' => 422],
        NoProviderForNotificationChannelException::class => ['type' => 'no-provider-for-notification-channel', 'title' => 'No provider is configured for this notification channel', 'status' => 422],
        UnsupportedCurrency::class => ['type' => 'unsupported-currency', 'title' => 'Unsupported currency', 'status' => 422],
        WebhookEndpointNotFound::class => ['type' => 'webhook-endpoint-not-found', 'title' => 'Webhook endpoint not found', 'status' => 404],
        InvalidWebhookSecretRotation::class => ['type' => 'invalid-webhook-secret-rotation', 'title' => 'Invalid webhook secret rotation', 'status' => 422],
        WebhookDeliveryNotFound::class => ['type' => 'webhook-delivery-not-found', 'title' => 'Webhook delivery not found', 'status' => 404],
        WebhookDeliveryNotDead::class => ['type' => 'webhook-delivery-not-dead', 'title' => 'Only a dead webhook delivery can be replayed', 'status' => 409],
        WebhookEndpointInactive::class => ['type' => 'webhook-endpoint-inactive', 'title' => 'The webhook endpoint is deactivated', 'status' => 409],
        NotificationNotFound::class => ['type' => 'notification-not-found', 'title' => 'Notification not found', 'status' => 404],
        MissingRecipientPhoneNumber::class => ['type' => 'missing-recipient-phone-number', 'title' => 'The recipient phone number is missing', 'status' => 422],
        ChannelDoesNotSupportSessions::class => ['type' => 'channel-does-not-support-sessions', 'title' => 'This channel has no linked-device session to manage', 'status' => 422],
        ChannelSessionNotFound::class => ['type' => 'channel-session-not-found', 'title' => 'WhatsApp session not found', 'status' => 422],
        MissingChannelSession::class => ['type' => 'missing-channel-session', 'title' => 'The WhatsApp session is missing', 'status' => 422],
        OperatorNotFound::class => ['type' => 'operator-not-found', 'title' => 'Operator not found', 'status' => 404],
        InvalidOperatorCredentials::class => ['type' => 'unauthenticated', 'title' => 'Unauthenticated', 'status' => 401],
        OperatorRevoked::class => ['type' => 'forbidden', 'title' => 'Forbidden', 'status' => 403],
        MfaChallengeInvalid::class => ['type' => 'unauthenticated', 'title' => 'Unauthenticated', 'status' => 401],
        InvalidMfaCode::class => ['type' => 'unauthenticated', 'title' => 'Unauthenticated', 'status' => 401],
        MfaAlreadyEnrolled::class => ['type' => 'mfa-already-enrolled', 'title' => 'MFA is already enabled', 'status' => 409],
        MfaNotEnrolled::class => ['type' => 'mfa-not-enrolled', 'title' => 'MFA enrollment has not been started', 'status' => 422],
        ProviderPermanentError::class => ['type' => 'provider-error', 'title' => 'The external provider rejected the request', 'status' => 502],
        ProviderAuthError::class => ['type' => 'provider-error', 'title' => 'The external provider rejected the request', 'status' => 502],
        ProviderTransientError::class => ['type' => 'provider-unavailable', 'title' => 'The external provider is temporarily unavailable', 'status' => 503],
        ProviderDisabledException::class => ['type' => 'provider-unavailable', 'title' => 'The external provider is temporarily unavailable', 'status' => 503],
        CircuitOpenException::class => ['type' => 'provider-unavailable', 'title' => 'The external provider is temporarily unavailable', 'status' => 503],
        // ADR-0069 §1 and §4 — the organization-membership refusals. They
        // live in `identity`'s `Contracts/` (not its Domain layer) so the
        // console can translate them into an HTTP status without breaking
        // G3c; the type, title and status below are the very ones the
        // console's `TranslatesOrganizationRefusals` produces, so a refusal
        // surfaced here and one surfaced by the console read identically.
        OrganizationNotVisible::class => ['type' => 'organization-not-found', 'title' => 'Organization not found', 'status' => 404],
        OrganizationMemberNotFound::class => ['type' => 'organization-member-not-found', 'title' => 'Member not found', 'status' => 404],
        OrganizationInvitationNotFound::class => ['type' => 'organization-invitation-not-found', 'title' => 'Invitation not found', 'status' => 404],
        OrganizationMemberManagementForbidden::class => ['type' => 'organization-members-forbidden', 'title' => 'Managing the members of this organization is not allowed', 'status' => 403],
        OrganizationPrivilegeEscalation::class => ['type' => 'privilege-escalation', 'title' => 'Privilege escalation is refused', 'status' => 403],
        PlatformRoleAssignmentForbidden::class => ['type' => 'platform-role-forbidden', 'title' => 'A platform role cannot be granted through an organization', 'status' => 403],
        LastOrganizationOwner::class => ['type' => 'last-owner', 'title' => 'The last owner of the organization cannot be removed', 'status' => 409],
        AlreadyOrganizationMember::class => ['type' => 'already-member', 'title' => 'Already a member of the organization', 'status' => 409],
        OrganizationInvitationAlreadyExists::class => ['type' => 'invitation-already-exists', 'title' => 'An invitation is already pending for this email', 'status' => 409],
        InvitationNotUsable::class => ['type' => 'invitation-not-usable', 'title' => 'This invitation can no longer be used', 'status' => 409],
        OperatorAlreadyExists::class => ['type' => 'operator-already-exists', 'title' => 'The console account of this email is revoked', 'status' => 409],
        // ADR-0069 §1 — one console account per mailbox: the refusal that
        // says so, instead of the database's unique-index error leaking
        // through as a mislabelled 422.
        OperatorEmailAlreadyTaken::class => ['type' => 'operator-email-already-taken', 'title' => 'This email address is already taken', 'status' => 409],
        InvalidOrganizationRole::class => ['type' => 'invalid-organization-role', 'title' => 'Invalid organization role', 'status' => 422],
        InvitationPasswordTooWeak::class => ['type' => 'invitation-password-too-weak', 'title' => 'The chosen password is too weak', 'status' => 422],
        InvitationAccountPasswordInvalid::class => ['type' => 'invitation-account-password-invalid', 'title' => 'The password of the existing account is required', 'status' => 422],
    ];

    public static function toResponse(Throwable $e): ?JsonResponse
    {
        $mapped = self::resolve($e::class);
        if ($mapped === null) {
            return null;
        }

        return ProblemDetails::make($mapped['type'], $mapped['title'], $mapped['status'], $e->getMessage());
    }

    /**
     * `$exceptionClass` is a plain `string`, not `class-string<Throwable>`
     * — Scramble's own inferred type (`ObjectType::$name`) is a bare
     * string, and this method degrades safely (returns `null`) for any
     * input that isn't one of the mapped classes, so the stricter type
     * isn't load-bearing here.
     *
     * @return array{type: string, title: string, status: int}|null
     */
    public static function resolve(string $exceptionClass): ?array
    {
        foreach (self::MAP as $class => $mapping) {
            if ($exceptionClass === $class || is_subclass_of($exceptionClass, $class)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Every mapped exception class — the Scramble extension iterates
     * this to know which exception types (discovered from a route's
     * `@throws` PHPDoc, per-controller) it can offer a documented
     * response for.
     *
     * @return list<class-string<Throwable>>
     */
    public static function exceptionClasses(): array
    {
        return array_keys(self::MAP);
    }
}
