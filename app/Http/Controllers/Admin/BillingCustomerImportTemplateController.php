<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\CustomerImportTemplate;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0122 §9 — the model of the file to import.
 *
 * The reader refuses any file whose header is not exactly the contract's
 * one. The operator must therefore never have to guess a column name:
 * this page hands him the file itself — the header, and one example
 * line, ready to be deposited.
 *
 * The model comes from `CustomerImportTemplate`, the one source the
 * reader reads too: the file the operator downloads and the file the
 * Core accepts can never disagree.
 *
 * Downloading is a **read**: the account only has to see the customers
 * of the project (`customers:read`). The import itself, which writes
 * them, keeps `customers:write`.
 */
final class BillingCustomerImportTemplateController
{
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
    ) {}

    public function show(OperatorContext $operator, string $projectId): Response
    {
        // The account must hold the permission somewhere: a refusal that
        // comes before any lookup never confirms that a project exists.
        $this->permittedScope($operator, 'customers:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:read');

        return response(CustomerImportTemplate::content(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.CustomerImportTemplate::FILENAME.'"',
        ]);
    }
}
