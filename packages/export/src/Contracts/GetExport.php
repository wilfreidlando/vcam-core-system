<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

use Core\Support\Access\AccessScope;

interface GetExport
{
    /**
     * The scope is **required** (ADR-0069 §2): an export of a project
     * outside it is « not found », never « forbidden ».
     *
     * @throws ExportNotFound
     */
    public function get(string $exportId, AccessScope $scope): ExportView;
}
