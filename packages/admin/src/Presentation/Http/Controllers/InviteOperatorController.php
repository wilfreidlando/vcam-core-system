<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationAlreadyExists;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0081 S-07 — invites an email address to hold a console account.
 *
 * The response carries the **activation token**, once. The account does
 * not exist yet: the invitee chooses their own password when they
 * activate the invitation (`POST /v1/admin/operator-invitations/accept`).
 * No password is chosen by the system and none is transmitted
 * (ADR-0069 §4) — the same one-shot-secret pattern as the API key
 * issuance, applied to the console account itself.
 *
 * Requires `operators:write` (Platform Admin only). MFA enrollment
 * happens on first login, not here.
 */
final class InviteOperatorController
{
    public function __construct(
        private readonly InviteOperator $inviteOperator,
    ) {}

    /**
     * Invite an email address to hold a console account.
     *
     * The response carries the **activation token**, once. The account
     * does not exist yet: the invitee chooses their own password when
     * they activate the invitation
     * (`POST /v1/admin/operator-invitations/accept`) — no password is
     * chosen by the system and none is transmitted (ADR-0081 S-07), the
     * same one-shot-secret pattern as the API key issuance, applied to
     * the console account itself.
     *
     * Requires `operators:write` (Platform Admin only). MFA enrollment
     * happens on first login, not here.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('operators:write')) {
            return ProblemDetails::forbidden('The operators:write permission is required.');
        }

        /** @var array{email: string, role?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            // ADR-0069 §1 — an empty role means an account without a
            // platform role, living in its organizations only.
            'role' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $invitation = $this->inviteOperator->invite(
                new InviteOperatorCommand($data['email'], $data['role'] ?? '', $operator->operatorId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OperatorEmailAlreadyTaken $e) {
            // ADR-0069 §1 — one console account per mailbox. Answered
            // before the generic catch, which would mislabel it as an
            // invalid role.
            return ProblemDetails::make('operator-email-already-taken', 'This email address is already taken', 409, $e->getMessage());
        } catch (OperatorInvitationAlreadyExists $e) {
            return ProblemDetails::make('operator-invitation-already-exists', 'An invitation is already pending for this email address', 409, $e->getMessage());
        } catch (RuntimeException $e) {
            // The concrete exception (InvalidOperatorRole) lives in
            // identity's Domain layer; admin must not reference it (G3c)
            // — the Contracts port's own docblock documents this generic
            // RuntimeException as the catchable type.
            return ProblemDetails::make('invalid-operator-role', 'Invalid operator role', 422, $e->getMessage());
        }

        return new JsonResponse([
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            // Shown once, at creation — never retrievable again.
            'token' => $invitation->token,
            'expires_at' => $invitation->expiresAt->format(DATE_ATOM),
        ], 201);
    }
}
