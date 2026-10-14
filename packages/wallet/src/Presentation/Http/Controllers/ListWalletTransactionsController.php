<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Contracts\ListWalletTransactions;
use Core\Wallet\Contracts\ListWalletTransactionsCommand;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Presentation\Http\Presenters\WalletJson;
use Core\Wallet\Presentation\Http\Requests\ListWalletTransactionsRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ListWalletTransactionsController
{
    public function __construct(
        private readonly ListWalletTransactions $transactions,
    ) {}

    /**
     * List a wallet's movements.
     *
     * Every credit and debit of the wallet, newest first, read from the ledger. `type` is
     * `payment` (a confirmed payment credited it — see `payment_id`), `transfer`,
     * `withdrawal` or `debit`; `reference`, `description` and `metadata` are those you sent
     * with the transfer or withdrawal. Requires the `wallets:read` scope.
     *
     * @throws WalletNotFound the wallet doesn't exist, or belongs to another project or environment (404)
     */
    public function __invoke(string $walletId, ListWalletTransactionsRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('wallets:read')) {
            return ProblemDetails::forbidden('The wallets:read scope is required.');
        }

        $page = $this->transactions->list(new ListWalletTransactionsCommand(
            $project->projectId,
            $project->environment->value,
            $walletId,
            max(1, $request->integer('page', 1)),
            min(100, max(1, $request->integer('per_page', 25))),
        ));

        return new JsonResponse([
            'data' => array_map(WalletJson::transaction(...), $page->items),
            'meta' => ['total' => $page->total, 'page' => $page->page, 'per_page' => $page->perPage],
        ]);
    }
}
