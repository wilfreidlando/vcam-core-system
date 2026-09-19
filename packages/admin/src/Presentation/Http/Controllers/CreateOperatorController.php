<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class CreateOperatorController
{
    public function __construct(
        private readonly CreateOperator $createOperator,
    ) {}

    /**
     * Create a platform operator.
     *
     * The plaintext password is returned once in this response and never
     * stored — the same one-shot-secret pattern as API key issuance and
     * the `identity:create-operator` CLI command, which this endpoint is
     * a self-service alternative to. Requires `operators:write` (Platform
     * Admin only). MFA enrollment happens on first login, not here.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('operators:write')) {
            return ProblemDetails::forbidden('The operators:write permission is required.');
        }

        /** @var array{email: string, name: string, role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string'],
        ]);

        try {
            $created = $this->createOperator->create(
                new CreateOperatorCommand($data['email'], $data['name'], $data['role']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            // The concrete exception (InvalidOperatorRole) lives in
            // identity's Domain layer; admin must not reference it (G3c)
            // — the Contracts port's own docblock documents this generic
            // RuntimeException as the catchable type.
            return ProblemDetails::make('invalid-operator-role', 'Invalid operator role', 422, $e->getMessage());
        }

        return new JsonResponse([
            'id' => $created->operatorId,
            'plaintext_password' => $created->plaintextPassword,
        ], 201);
    }
}
