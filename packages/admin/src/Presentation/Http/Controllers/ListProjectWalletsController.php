<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletsCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ListProjectWalletsController
{
    public function __construct(
        private readonly ListWallets $listWallets,
    ) {}

    /**
     * List a Project's wallets, with their current balance.
     *
     * Requires `wallets:read`.
     */
    public function __invoke(Request $request, string $projectId, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('wallets:read')) {
            return ProblemDetails::forbidden('The wallets:read permission is required.');
        }

        Validator::make(['projectId' => $projectId], ['projectId' => 'uuid'])->validate();

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listWallets->list(new ListWalletsCommand($projectId, $page, $perPage));

        return new JsonResponse([
            'data' => array_map(static fn ($wallet): array => [
                'id' => $wallet->id,
                'party_id' => $wallet->partyId,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
                'available_minor' => $wallet->availableMinor,
                'held_minor' => $wallet->heldMinor,
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
