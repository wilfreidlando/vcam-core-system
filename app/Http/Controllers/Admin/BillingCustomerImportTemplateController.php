<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\CustomerImportTemplate;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0122 §9 — le model du fichier à importer.
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
 * **The model follows the form in force**: a project that declared the
 * shape of its customers gets a model that carries that shape (ADR-0122
 * §5) — the file he downloads is the file the Core will read.
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
        private readonly GetTenantSchema $tenantSchemas,
    ) {}

    public function show(OperatorContext $operator, string $projectId): Response
    {
        // The account must hold the permission somewhere: a refusal that
        // comes before any lookup never confirms that a project exists.
        $scope = $this->permittedScope($operator, 'customers:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:read');

        $schema = $this->tenantSchemas->accepted($projectId, $scope);

        return response(CustomerImportTemplate::content($schema), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.CustomerImportTemplate::FILENAME.'"',
        ]);
    }
}
