<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

interface ListApiKeys
{
    /**
     * Newest first. Never paginated — a Project's own API key count stays
     * small in practice (unlike payments/operators), so there is no
     * `page`/`perPage` on `ListApiKeysCommand`.
     *
     * @return list<ApiKeySummaryView>
     */
    public function list(ListApiKeysCommand $command): array;
}
