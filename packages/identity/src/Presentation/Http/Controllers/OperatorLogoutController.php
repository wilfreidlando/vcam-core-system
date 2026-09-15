<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Application\Dto\LogoutOperatorCommand;
use Core\Identity\Application\UseCase\LogoutOperator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperatorLogoutController
{
    public function __construct(
        private readonly LogoutOperator $logoutOperator,
    ) {}

    /**
     * Revoke the operator's current access token.
     *
     * The token in the `Authorization` header stops working immediately.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? '';

        ($this->logoutOperator)(new LogoutOperatorCommand($token));

        return new JsonResponse(status: 204);
    }
}
