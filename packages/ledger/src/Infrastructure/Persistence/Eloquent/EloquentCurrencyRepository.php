<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Core\Ledger\Domain\Port\CurrencyRepository;

final class EloquentCurrencyRepository implements CurrencyRepository
{
    public function isSupported(string $code): bool
    {
        // `exists()` is forwarded by Eloquent\Builder to the underlying
        // query builder rather than declared on it directly — going
        // through `toBase()` avoids a Larastan false positive ("dynamic
        // call to static method"), same pattern used elsewhere in the
        // Core for `orderBy`/`skip`/`take`/`count`.
        return CurrencyRecord::query()->where('code', $code)->toBase()->exists();
    }
}
