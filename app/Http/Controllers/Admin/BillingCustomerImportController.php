<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\CustomerImportTemplate;
use App\Http\View\TenantSchemaLabels;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerDeclaredAttributeRefused;
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

            $resolved = $this->resolve($row['values'], $schema);

            $reason = $this->refusal($projectId, $row['values'], $scope, $schema, $resolved);

            if ($reason !== null) {
                $refusals[] = ['line' => $row['line'], 'reference' => $reference, 'reason' => $reason];

                continue;
            }

            $existing = $this->customers->get(new GetCustomerCommand($projectId, $reference), $scope);

            try {
                $this->upsertCustomer->upsert(new UpsertCustomerCommand(
                    projectId: $projectId,
                    externalRef: $reference,
                    displayName: $resolved['identity']['display_name'],
                    email: $resolved['identity']['email'],
                    phone: $resolved['identity']['phone'],
                    locale: $resolved['identity']['locale'],
                    metadata: [],
                    expectedVersion: null,
                    actor: $this->actor($operator),
                    correlationId: null,
                    // ADR-0130 §1 — les valeurs déclarées voyagent avec
                    // le client : elles sont écrites avant l'événement,
                    // dans la même transaction.
                    declaredAttributes: $resolved['declared'],
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
            } catch (CustomerDeclaredAttributeRefused) {
                $refusals[] = [
                    'line' => $row['line'],
                    'reference' => $reference,
                    'reason' => 'Une valeur déclarée ne correspond pas à la forme en vigueur.',
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
     * @param array{identity: array{display_name: string, email: ?string, phone: ?string, locale: string}, declared: array<string, string>} $resolved
     */
    private function refusal(string $projectId, array $values, AccessScope $scope, ?TenantSchemaView $schema, array $resolved): ?string
    {
        $reference = $values['external_ref'] ?? '';

        if ($reference === '') {
            return 'La référence est vide.';
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $reference) !== 1) {
            return 'La référence ne respecte pas le format du Core (lettres, chiffres et . _ : -).';
        }

        if (! $this->tenantSchemas->acceptsReference($projectId, $reference, $scope)) {
            $pattern = $schema?->uniqueKeyPattern;

            return $pattern === null
                ? 'La référence ne respecte pas le format en vigueur.'
                : sprintf('La référence ne respecte pas le format en vigueur (%s).', $pattern);
        }

        // ADR-0122 §5 — les valeurs déclarées sont tenues par la forme
        // en vigueur : une valeur obligatoire et vide, ou une valeur qui
        // ne tient pas son type, refuse la ligne.
        $declaredReason = $this->declaredRefusal($values, $schema);

        if ($declaredReason !== null) {
            return $declaredReason;
        }

        if ($resolved['identity']['display_name'] === '') {
            return 'Le nom est vide.';
        }

        if ($resolved['identity']['email'] === null) {
            return 'L\'adresse électronique est absente.';
        }

        if (filter_var($resolved['identity']['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'L\'adresse électronique n\'est pas valide.';
        }

        return null;
    }

    /**
     * Why a declared value refuses the line, or `null` when it holds.
     *
     * @param array<string, string> $values
     */
    private function declaredRefusal(array $values, ?TenantSchemaView $schema): ?string
    {
        if ($schema === null) {
            return null;
        }

        foreach ($schema->fields as $field) {
            $value = trim($values[$field->key] ?? '');

            if ($value === '') {
                if ($field->required) {
                    return sprintf('Le champ « %s » est obligatoire.', $field->label);
                }

                continue;
            }

            if ($field->maxLength !== null && mb_strlen($value) > $field->maxLength) {
                return sprintf('Le champ « %s » ne peut pas dépasser %d caractères.', $field->label, $field->maxLength);
            }

            if (! $this->holdsItsType($field, $value)) {
                return sprintf('Le champ « %s » ne porte pas une valeur de son type.', $field->label);
            }
        }

        return null;
    }

    /**
     * Whether a value is the kind of value the declaration announced.
     */
    private function holdsItsType(TenantSchemaFieldView $field, string $value): bool
    {
        return match ($field->type) {
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'decimal' => is_numeric($value),
            'boolean' => in_array(mb_strtolower($value), ['1', '0', 'true', 'false', 'oui', 'non', 'yes', 'no'], true),
            'date', 'datetime' => strtotime($value) !== false,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => true,
        };
    }

    /**
     * The identity and the declared values a row carries.
     *
     * A declared key whose source names one of the Core's own identity
     * fields **replaces** it (ADR-0122 §5) : the value is typed once, and
     * it feeds the identity of the client. Every other declared key is
     * written with the client, comme ses attributs déclarés
     * (ADR-0130 §1).
     *
     * @param array<string, string> $values
     * @return array{identity: array{display_name: string, email: ?string, phone: ?string, locale: string}, declared: array<string, string>}
     */
    private function resolve(array $values, ?TenantSchemaView $schema): array
    {
        $identity = [
            'display_name' => trim($values['display_name'] ?? ''),
            'email' => $this->orNull($values['email'] ?? ''),
            'phone' => $this->orNull($values['phone'] ?? ''),
            'locale' => 'fr',
        ];

        $declared = [];

        if ($schema === null) {
            return ['identity' => $identity, 'declared' => $declared];
        }

        foreach ($schema->fields as $field) {
            $value = trim($values[$field->key] ?? '');

            if ($value === '') {
                continue;
            }

            $core = TenantSchemaLabels::identityField($field->source);

            if ($core === null) {
                $declared[$field->key] = $value;

                continue;
            }

            match ($core) {
                'display_name' => $identity['display_name'] = $value,
                'email' => $identity['email'] = $value,
                'phone' => $identity['phone'] = $value,
                'locale' => $identity['locale'] = $value,
                default => null,
            };
        }

        return ['identity' => $identity, 'declared' => $declared];
    }

    /**
     * A cell that carries nothing is no value at all.
     */
    private function orNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }
}
