<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Contracts\ListPartyWallets;
use Core\Wallet\Contracts\ListPartyWalletsCommand;
use Core\Wallet\Presentation\Http\Presenters\WalletJson;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ListPartyWalletsController
{
    public function __construct(
        private readonly ListPartyWallets $wallets,
    ) {}

    /**
     * List a party's wallets.
     *
     * The wallets of the party you name by your own reference (URL-encoded), one per
     * currency, with their balance — in the environment of your key. A party that never
     * received anything has none: the list is empty, and reading never creates the party.
     * Requires the `wallets:read` scope.
     */
    public function __invoke(string $externalRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('wallets:read')) {
            return ProblemDetails::forbidden('The wallets:read scope is required.');
        }

        $wallets = $this->wallets->list(new ListPartyWalletsCommand(
            $project->projectId,
            $project->environment->value,
            // The router already decoded the path segment.
            $externalRef,
        ));

        return new JsonResponse(['data' => array_map(WalletJson::wallet(...), $wallets)]);
    }
}
