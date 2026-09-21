<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletsCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wallet visibility, read-only, full stop. ADR-0033 §1: no HTTP endpoint
 * mutates a wallet. ADR-0037: a wallet is credited only as an explicit
 * side effect of a payment's own initiation, never from the admin
 * console. This controller exposes `ListWallets` — a pure read — and
 * nothing else; there is deliberately no credit/debit form, button or
 * route here.
 *
 * No ledger-entry-history drill-down: `packages/ledger/src/Contracts/`
 * has no read port for a wallet's entry history (only posting/account
 * ports), so a per-wallet detail view is out of scope for this pass —
 * inventing one would mean adding a new Contracts port to a module this
 * pass has no visibility into (two sibling agents are concurrently
 * touching `packages/identity`/`packages/payment`).
 */
final class WalletsController
{
    public function __construct(
        private readonly ListWallets $listWallets,
        private readonly GetProject $getProject,
    ) {}

    public function index(string $projectId, Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('wallets:read')) {
            abort(403, 'The wallets:read permission is required.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Project not found.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $searchRaw = (string) $request->query('search', '');

        $pageResult = $this->listWallets->list(new ListWalletsCommand(
            projectId: $projectId,
            page: $page,
            perPage: $perPage,
            search: $searchRaw === '' ? null : $searchRaw,
        ));

        return response()->view('admin.wallets.index', [
            'title' => 'Wallets · '.$project->name,
            'project' => $project,
            'wallets' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $this->summarize($projectId),
            'search' => $searchRaw,
        ]);
    }

    /**
     * Per-currency wallet totals for the stats cards at the top of the
     * page. `ListWallets` is paginated with no "get everything" mode, so
     * this fetches a separate, larger page (capped at 500) purely to
     * aggregate — same documented-limitation pattern already used for
     * the organizations dropdown (`ProjectsController::create()`, capped
     * at 100). A project with more wallets than that undercounts here;
     * acceptable for this MVP-scale read-only visibility pass.
     *
     * @return array{total: int, byCurrency: array<string, array{count: int, availableMinor: int, heldMinor: int}>}
     */
    private function summarize(string $projectId): array
    {
        $page = $this->listWallets->list(new ListWalletsCommand(
            projectId: $projectId,
            page: 1,
            perPage: 500,
        ));

        $byCurrency = [];
        foreach ($page->items as $wallet) {
            $byCurrency[$wallet->currency] ??= ['count' => 0, 'availableMinor' => 0, 'heldMinor' => 0];
            $byCurrency[$wallet->currency]['count']++;
            $byCurrency[$wallet->currency]['availableMinor'] += $wallet->availableMinor;
            $byCurrency[$wallet->currency]['heldMinor'] += $wallet->heldMinor;
        }

        return ['total' => $page->total, 'byCurrency' => $byCurrency];
    }
}
