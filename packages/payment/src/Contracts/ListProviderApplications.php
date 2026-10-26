<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;

interface ListProviderApplications
{
    /**
     * The scope is required (ADR-0069 §2).
     *
     * @return list<ProviderApplicationView>
     */
    public function list(ListProviderApplicationsCommand $command, AccessScope $scope): array;
}
