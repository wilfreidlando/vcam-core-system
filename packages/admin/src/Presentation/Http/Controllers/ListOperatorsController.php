<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ListOperatorsController
{
    public function __construct(
        private readonly ListOperators $listOperators,
    ) {}

    /**
     * List platform operators.
     *
     * Requires `operators:read` (Platform Admin only).
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('operators:read')) {
            return ProblemDetails::forbidden('The operators:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listOperators->list(new ListOperatorsCommand($page, $perPage));

        return new JsonResponse([
            'data' => array_map(static fn ($op): array => [
                'id' => $op->id,
                'email' => $op->email,
                'name' => $op->name,
                'role' => $op->role,
                'mfa_enabled' => $op->mfaEnabled,
                'revoked' => $op->revoked,
                'created_at' => $op->createdAt->format(DATE_ATOM),
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
