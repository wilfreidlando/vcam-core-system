<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\UpdateOperator;
use Core\Identity\Contracts\UpdateOperatorCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class UpdateOperatorController
{
    public function __construct(
        private readonly UpdateOperator $updateOperator,
    ) {}

    /**
     * Update a platform operator's profile (email/name/role).
     *
     * Never touches credentials or MFA state — those are separate flows.
     * Requires `operators:write` (Platform Admin only). Audits internally
     * — `identity` has `audit` module access. Returns 204 — the port
     * returns void.
     */
    public function __invoke(Request $request, string $operatorId, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('operators:write')) {
            return ProblemDetails::forbidden('The operators:write permission is required.');
        }

        Validator::make(['operatorId' => $operatorId], ['operatorId' => 'uuid'])->validate();

        /** @var array{email: string, name: string, role: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            // ADR-0069 §1 — the platform role is optional: an empty role
            // means "no platform role at all" (the account keeps only its
            // organization memberships). The key must still be present, so
            // a partial update never clears the role by accident.
            'role' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $this->updateOperator->update(
                new UpdateOperatorCommand($operatorId, $data['email'], $data['name'], $data['role'] ?? ''),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OperatorEmailAlreadyTaken $e) {
            // ADR-0069 §1 — one console account per mailbox. Answered
            // before the generic catch, which would mislabel it.
            return ProblemDetails::make('operator-email-already-taken', 'This email address is already taken', 409, $e->getMessage());
        } catch (RuntimeException $e) {
            // The concrete exception types (OperatorNotFound,
            // InvalidOperatorRole) live in identity's Domain layer; admin
            // must not reference them (G3c) — the Contracts port's own
            // docblock documents this generic RuntimeException as the
            // catchable type, so a not-found is distinguished from an
            // invalid role only by message content, on a best-effort basis.
            if (str_contains($e->getMessage(), 'is not a valid operator role')) {
                return ProblemDetails::make('invalid-operator-role', 'Invalid operator role', 422, $e->getMessage());
            }

            return ProblemDetails::make('operator-not-found', 'Operator not found', 404, $e->getMessage());
        }

        return new JsonResponse(null, 204);
    }
}
