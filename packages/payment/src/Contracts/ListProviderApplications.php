<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

interface ListProviderApplications
{
    /**
     * @return list<ProviderApplicationView>
     */
    public function list(ListProviderApplicationsCommand $command): array;
}
