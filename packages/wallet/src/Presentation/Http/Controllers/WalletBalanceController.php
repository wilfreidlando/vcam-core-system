<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Contracts\GetWalletBalance;
use Core\Wallet\Contracts\GetWalletBalanceCommand;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only public HTTP surface Wallet exposes in Lot 2 (ADR-0033 §1):
 * read-only. Every mutation is an Application use case invoked by another
 * module (Payment, Lot 3) or an ops command — never a public endpoint, to
 * avoid letting any authenticated Project credit value into its own wallets
 * on demand.
 */
final class WalletBalanceController
{
    public function __construct(
        private readonly GetWalletBalance $getBalance,
    ) {}

    /**
     * Get a wallet's balance.
     *
     * Read-only (ADR-0033 §1): Wallet exposes no HTTP endpoint that mutates
     * a balance. Requires the `wallets:read` scope, and returns the same
     * not-found response whether the wallet doesn't exist or belongs to
     * another project — the two are indistinguishable on purpose.
     *
     * @throws WalletNotFound the wallet doesn't exist, or belongs to another project (404)
     */
    public function __invoke(string $walletId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('wallets:read')) {
            return ProblemDetails::forbidden('The wallets:read scope is required.');
        }

        $balance = $this->getBalance->get(new GetWalletBalanceCommand($walletId, $project->projectId));

        return new JsonResponse([
            'wallet_id' => $balance->walletId,
            'currency' => $balance->currency,
            'available_minor' => $balance->availableMinor,
            'held_minor' => $balance->heldMinor,
        ]);
    }
}
