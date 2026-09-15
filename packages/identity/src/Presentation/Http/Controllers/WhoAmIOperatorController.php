<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\JsonResponse;

/**
 * The operator-plane equivalent of `WhoAmIController` — the first call an
 * operator console session makes to confirm its token and role.
 */
final class WhoAmIOperatorController
{
    /**
     * Identify the calling operator.
     *
     * Resolves the bearer token and returns which operator it belongs to
     * and their role. The first call an operator console session should
     * make to confirm its token.
     */
    public function __invoke(OperatorContext $operator): JsonResponse
    {
        return new JsonResponse([
            'operator_id' => $operator->operatorId,
            'email' => $operator->email,
            'role' => $operator->role->value,
        ]);
    }
}
