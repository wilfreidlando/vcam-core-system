<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use InvalidArgumentException;

/**
 * ADR-0088 §3 — the wallets of a project and their movements, in the
 * shape of the **public API**, page after page, for the project export.
 */
interface WalletExportRecords
{
    /**
     * @return list<string>
     */
    public function types(): array;

    /**
     * `wallets`: the wallets, as `GET /v1/parties/{ref}/wallets` lists
     * them. `wallet_transactions`: every movement of every wallet, as
     * `GET /v1/wallets/{id}/transactions` lists them, plus the `wallet_id`
     * it belongs to.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws InvalidArgumentException the type or the cursor is unknown
     */
    public function page(string $type, string $projectId, ?string $cursor, int $limit): array;
}
