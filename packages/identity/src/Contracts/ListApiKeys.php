<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

interface ListApiKeys
{
    /**
     * Newest first. Never paginated — a Project's own API key count stays
     * small in practice (unlike payments/operators), so there is no
     * `page`/`perPage` on `ListApiKeysCommand`.
     *
     * The scope is required (ADR-0069 §2): a key of a project outside the
     * account's organizations is not listed at all.
     *
     * @return list<ApiKeySummaryView>
     */
    public function list(ListApiKeysCommand $command, AccessScope $scope): array;
}
