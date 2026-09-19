<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\RevokeOperator;
use Core\Identity\Contracts\RevokeOperatorCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RevokeOperatorController
{
    public function __construct(
        private readonly RevokeOperator $revokeOperator,
    ) {}

    /**
     * Revoke a platform operator's access.
     *
     * Idempotent — revoking an already-revoked operator succeeds without
     * effect. Requires `operators:write` (Platform Admin only). A
     * revoked operator's existing bearer tokens are rejected on their
     * very next request (`ResolveOperatorFromBearerToken` re-checks
     * revocation every time), so this takes effect immediately.
     */
    public function __invoke(string $operatorId, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('operators:write')) {
            return ProblemDetails::forbidden('The operators:write permission is required.');
        }

        Validator::make(['operatorId' => $operatorId], ['operatorId' => 'uuid'])->validate();

        try {
            $this->revokeOperator->revoke(
                new RevokeOperatorCommand($operatorId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            // The concrete exception (OperatorNotFound) lives in
            // identity's Domain layer; admin must not reference it (G3c).
            return ProblemDetails::make('operator-not-found', 'Operator not found', 404, $e->getMessage());
        }

        return new JsonResponse(null, 204);
    }
}
