<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
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
 * Le fichier exporté ne porte que l'identité du Core (référence, nom,
 * contact) : il ne porte aucune valeur déclarée, et la console n'en invente
 * aucune.
 */
final class BillingCustomerImportController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    /**
     * Les colonnes du fichier exporté par l'application.
     *
     * @var list<string>
     */
    private const COLUMNS = ['external_ref', 'display_name', 'email', 'phone'];

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetTenantSchema $tenantSchemas,
        private readonly GetCustomer $customers,
        private readonly UpsertCustomer $upsertCustomer,
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
                'report' => null,
            ]);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return back()->withInput()->withErrors(['file' => 'Choisissez le fichier à importer.']);
        }

        try {
            $rows = $this->readRows($file);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['file' => $exception->getMessage()]);
        }

        $report = $this->import($projectId, $rows, $scope, $schema?->uniqueKeyPattern, $operator);

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
     * @return list<array{line: int, values: array<string, string>, malformed: bool}>
     *
     * @throws RuntimeException the file is not an export the Core knows
     */
    private function readRows(UploadedFile $file): array
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
            $expected = self::COLUMNS;
            sort($sorted);
            sort($expected);

            if ($sorted !== $expected) {
                throw new RuntimeException(sprintf(
                    'L\'en-tête du fichier doit porter exactement les colonnes %s ; il porte %s.',
                    implode(', ', self::COLUMNS),
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
    private function import(string $projectId, array $rows, AccessScope $scope, ?string $pattern, OperatorContext $operator): array
    {
        $created = 0;
        $known = [];
        $refusals = [];

        foreach ($rows as $row) {
            $reference = $row['values']['external_ref'] ?? '';

            if ($row['malformed']) {
                $refusals[] = [
                    'line' => $row['line'],
                    'reference' => $reference,
                    'reason' => 'La ligne ne porte pas le même nombre de colonnes que l\'en-tête.',
                ];

                continue;
            }

            $reason = $this->refusal($projectId, $row['values'], $scope, $pattern);

            if ($reason !== null) {
                $refusals[] = ['line' => $row['line'], 'reference' => $reference, 'reason' => $reason];

                continue;
            }

            $existing = $this->customers->get(new GetCustomerCommand($projectId, $reference), $scope);

            try {
                $this->upsertCustomer->upsert(new UpsertCustomerCommand(
                    projectId: $projectId,
                    externalRef: $reference,
                    displayName: $row['values']['display_name'],
                    email: $row['values']['email'],
                    phone: $row['values']['phone'] !== '' ? $row['values']['phone'] : null,
                    locale: 'fr',
                    metadata: [],
                    expectedVersion: null,
                    actor: $this->actor($operator),
                    correlationId: null,
                ));
            } catch (CustomerEmailAlreadyTaken) {
                $refusals[] = [
                    'line' => $row['line'],
                    'reference' => $reference,
                    'reason' => 'Cette adresse électronique est déjà portée par un autre client.',
                ];

                continue;
            } catch (InvalidCustomerTransition) {
                $refusals[] = [
                    'line' => $row['line'],
                    'reference' => $reference,
                    'reason' => 'Ce client a été effacé : son identité ne peut plus être déclarée.',
                ];

                continue;
            }

            if ($existing === null) {
                $created++;

                continue;
            }

            // The client was already known: it was **linked**, never
            // duplicated (ADR-0122 §9 — the reference is unique per
            // project).
            $known[] = ['line' => $row['line'], 'reference' => $reference];
        }

        return [
            'lines' => count($rows),
            'created' => $created,
            'alreadyKnown' => count($known),
            'refused' => count($refusals),
            'refusals' => $refusals,
            'known' => $known,
        ];
    }

    /**
     * Why this row cannot be written, or `null` when it can.
     *
     * @param array<string, string> $values
     */
    private function refusal(string $projectId, array $values, AccessScope $scope, ?string $pattern): ?string
    {
        $reference = $values['external_ref'] ?? '';

        if ($reference === '') {
            return 'La référence est vide.';
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $reference) !== 1) {
            return 'La référence ne respecte pas le format du Core (lettres, chiffres et . _ : -).';
        }

        if (! $this->tenantSchemas->acceptsReference($projectId, $reference, $scope)) {
            return $pattern === null
                ? 'La référence ne respecte pas le format en vigueur.'
                : sprintf('La référence ne respecte pas le format en vigueur (%s).', $pattern);
        }

        if (($values['display_name'] ?? '') === '') {
            return 'Le nom est vide.';
        }

        if (($values['email'] ?? '') === '') {
            return 'L\'adresse électronique est absente.';
        }

        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'L\'adresse électronique n\'est pas valide.';
        }

        return null;
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }
}
