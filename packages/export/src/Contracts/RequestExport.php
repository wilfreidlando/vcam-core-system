<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

/**
 * ADR-0088 §3 — asks for the export of a project. Asynchronous: the export
 * is recorded `queued`, audited and handed to the queue; the file is built
 * outside the request.
 */
interface RequestExport
{
    public function request(RequestExportCommand $command): ExportView;
}
