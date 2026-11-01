<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Export;

use Core\Support\Access\AccessScope;
use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletsCommand;
use Core\Wallet\Contracts\ListWalletTransactions;
use Core\Wallet\Contracts\ListWalletTransactionsCommand;
use Core\Wallet\Contracts\WalletExportRecords;
use Core\Wallet\Presentation\Http\Presenters\WalletJson;
use InvalidArgumentException;

/**
 * The wallet rows of the project export. The wallet ports are paginated by
 * page number; the reader hides it behind an opaque cursor:
 *
 * - `wallets`: the cursor is the page number;
 * - `wallet_transactions`: `<wallet page>:<wallet index>:<movement page>` —
 *   the movements of one wallet after the other, a bounded batch at a time.
 */
final readonly class WalletExportRecordsReader implements WalletExportRecords
{
    private const WALLETS_PER_PAGE = 100;

    public function __construct(
        private ListWallets $wallets,
        private ListWalletTransactions $transactions,
    ) {}

    public function types(): array
    {
        return ['wallets', 'wallet_transactions'];
    }

    public function page(string $type, string $projectId, ?string $cursor, int $limit): array
    {
        return match ($type) {
            'wallets' => $this->wallets($projectId, $cursor),
            'wallet_transactions' => $this->movements($projectId, $cursor, $limit),
            default => throw new InvalidArgumentException("Unknown wallet export type '{$type}'."),
        };
    }

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function wallets(string $projectId, ?string $cursor): array
    {
        $number = $this->numbers($cursor, 1)[0];
        $page = $this->wallets->list(new ListWalletsCommand($projectId, $number, self::WALLETS_PER_PAGE), AccessScope::forProject($projectId));

        return [
            'rows' => array_map(WalletJson::summary(...), $page->items),
            'next_cursor' => $number * self::WALLETS_PER_PAGE < $page->total ? (string) ($number + 1) : null,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function movements(string $projectId, ?string $cursor, int $limit): array
    {
        [$walletPage, $walletIndex, $movementPage] = $this->numbers($cursor, 3);
        $walletIndex--;
        $wallets = $this->wallets->list(new ListWalletsCommand($projectId, $walletPage, self::WALLETS_PER_PAGE), AccessScope::forProject($projectId));

        $wallet = $wallets->items[$walletIndex] ?? null;

        if ($wallet === null) {
            return ['rows' => [], 'next_cursor' => null];
        }

        $page = $this->transactions->list(new ListWalletTransactionsCommand($projectId, $wallet->id, $movementPage, min(100, max(1, $limit))));
        $rows = array_map(
            static fn ($movement): array => ['wallet_id' => $wallet->id] + WalletJson::transaction($movement),
            $page->items,
        );

        if ($movementPage * $page->perPage < $page->total) {
            $next = $walletPage.':'.($walletIndex + 1).':'.($movementPage + 1);
        } elseif ($walletIndex + 1 < count($wallets->items)) {
            $next = $walletPage.':'.($walletIndex + 2).':1';
        } elseif ($walletPage * self::WALLETS_PER_PAGE < $wallets->total) {
            $next = ($walletPage + 1).':1:1';
        } else {
            $next = null;
        }

        return ['rows' => $rows, 'next_cursor' => $next];
    }

    /**
     * @return list<int>
     */
    private function numbers(?string $cursor, int $expected): array
    {
        if ($cursor === null || $cursor === '') {
            return array_fill(0, $expected, 1);
        }

        $parts = explode(':', $cursor);

        if (count($parts) !== $expected) {
            throw new InvalidArgumentException('Invalid wallet export cursor.');
        }

        $numbers = [];
        foreach ($parts as $part) {
            if (preg_match('/^[1-9][0-9]{0,8}$/', $part) !== 1) {
                throw new InvalidArgumentException('Invalid wallet export cursor.');
            }
            $numbers[] = (int) $part;
        }

        return $numbers;
    }
}
