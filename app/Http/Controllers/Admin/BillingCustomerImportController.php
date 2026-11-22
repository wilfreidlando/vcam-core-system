<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\ImportCustomerRows;
use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\CustomerImportTemplate;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0122 §9 — l'import en lot des clients qu'une application avait déjà.
 *
 * L'application exporte ses clients (une lecture seule, aucun droit, aucun
 * appel au Core) ; l'opérateur importe ce fichier. Le Core **crée** les
 * clients par le chemin de provisionnement qu'il connaît déjà — jamais un
 * code de doublon, jamais un cas particulier — et **pousse** l'événement de
 * création : l'application, qui connaît déjà ces clients, les lie.
 *
 * Deux règles tiennent l'écran :
 *
 *  1. **une ligne hors motif est refusée** — la référence est confrontée à
 *     la forme en force avant d'être écrite (ADR-0122 §6) ;
 *  2. **un import partiel est visible** — le rapport dit ce qui a été
 *     créé, ce qui existait déjà et ce qui a été refusé, avec la raison de
 *     chaque refus (ADR-0122 §10).
 *
 * **Le fichier porte la forme en vigueur.** Une déclaration acceptée
 * remplace l'identité du Core champ par champ (ADR-0122 §5) : le fichier
 * porte alors une colonne par clé déclarée, et les colonnes du Core
 * seulement pour les champs que la déclaration ne remplace pas. Les
 * valeurs déclarées sont écrites avec le client, dans la même transaction
 * (ADR-0130 §1).
 */
final class BillingCustomerImportController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetTenantSchema $tenantSchemas,
        private readonly ImportCustomerRows $importRows,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * The import screen: the page that asks for the file, and the import
     * itself.
     *
     * One route, two gestures: the operator comes here to be told what
     * the file must look like (GET), and comes back with the file (POST).
     */
    public function handle(Request $request, OperatorContext $operator, string $projectId): Response|RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:write');

        $schema = $this->tenantSchemas->accepted($projectId, $scope);

        if ($request->isMethod('GET')) {
            return response()->view('admin.billing.customers.import', [
                'title' => 'Import de clients',
                'projectId' => $projectId,
                'pattern' => $schema?->uniqueKeyPattern,
                'columns' => CustomerImportTemplate::columns($schema),
                'report' => null,
            ]);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return back()->withInput()->withErrors(['file' => 'Choisissez le fichier à importer.']);
        }

        try {
            $rows = $this->readRows($file, $schema);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['file' => $exception->getMessage()]);
        }

        $report = $this->import($projectId, $rows, $scope, $schema, $operator);

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.customers.imported',
            resource: 'project:'.$projectId,
            before: null,
            // ADR-0083 §4 — the audit counts the rows; it never carries a
            // value of a customer, and never the value of a refused row.
            after: ['created' => $report['created'], 'already_known' => $report['alreadyKnown'], 'refused' => $report['refused']],
            context: ['project_id' => $projectId, 'lines' => $report['lines']],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return response()->view('admin.billing.customers.import', [
            'title' => 'Import de clients',
            'projectId' => $projectId,
            'pattern' => $schema?->uniqueKeyPattern,
            'columns' => CustomerImportTemplate::columns($schema),
            'report' => $report,
        ]);
    }

    /**
     * The rows of the exported file, read as they are.
     *
     * The header is the contract between the application and the Core: a
     * file whose first line is not that header, or which carries another
     * column, is **refused** — never interpreted (ADR-0122 §9).
     *
     * The header itself is not written here: it comes from
     * `CustomerImportTemplate`, the one source the downloadable model
     * reads too — the model and the reader can never disagree.
     *
     * @return list<array{line: int, values: array<string, string>, malformed: bool}>
     *
     * @throws RuntimeException the file is not an export the Core knows
     */
    private function readRows(UploadedFile $file, ?TenantSchemaView $schema): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new RuntimeException('Le fichier n\'a pas pu être lu.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false) {
                throw new RuntimeException('Le fichier est vide : il doit commencer par une ligne d\'en-tête.');
            }

            $columns = array_map(static fn (?string $name): string => trim((string) $name), $header);

            if (trim(implode('', $columns)) === '') {
                throw new RuntimeException('Le fichier est vide : il doit commencer par une ligne d\'en-tête.');
            }

            $sorted = $columns;
            $expected = CustomerImportTemplate::columnNames($schema);
            sort($sorted);
            sort($expected);

            if ($sorted !== $expected) {
                throw new RuntimeException(sprintf(
                    'L\'en-tête du fichier doit porter exactement les colonnes %s ; il porte %s.',
                    implode(', ', CustomerImportTemplate::columnNames($schema)),
                    implode(', ', $columns),
                ));
            }

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;

                $cells = array_map(static fn (?string $value): string => trim((string) $value), $values);

                if (count($cells) === 1 && $cells[0] === '') {
                    // A blank line carries nothing.
                    continue;
                }

                if (count($cells) !== count($columns)) {
                    // A shifted row is not interpreted: it is refused.
                    $rows[] = ['line' => $line, 'values' => [], 'malformed' => true];

                    continue;
                }

                $row = [];
                foreach ($columns as $index => $column) {
                    $row[$column] = $cells[$index] ?? '';
                }

                $rows[] = ['line' => $line, 'values' => $row, 'malformed' => false];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The import itself: one customer per row, and a reason for every row
     * the Core refuses.
     *
     * The write path is **shared** with the clients the Core pulls from a
     * client application (ADR-0133 §4): the file and the pulled list reach
     * the same service, so they cannot diverge.
     *
     * @param list<array{line: int, values: array<string, string>, malformed: bool}> $rows
     * @return array{
     *     lines: int,
     *     created: int,
     *     alreadyKnown: int,
     *     refused: int,
     *     refusals: list<array{line: int, reference: string, reason: string}>,
     *     known: list<array{line: int, reference: string}>
     * }
     */
    private function import(string $projectId, array $rows, AccessScope $scope, ?TenantSchemaView $schema, OperatorContext $operator): array
    {
        return $this->importRows->import(
            $projectId,
            $rows,
            $scope,
            $schema,
            $this->actor($operator),
        );
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }
}
