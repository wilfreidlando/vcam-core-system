<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use App\Billing\ImportCustomerRows;
use App\Http\View\CustomerImportTemplate;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Support\Access\AccessScope;

/**
 * ADR-0133 §4 — les clients de l'application, ramenés chez le Core.
 *
 * Le Core interroge l'application, transforme ses lignes en **lignes du
 * Core** — une colonne par clé, dans les colonnes que la forme en vigueur
 * attend — et les confie au **même** chemin d'écriture que l'import en
 * lot (ADR-0133 §4). Un client déjà connu est mis à jour, jamais
 * dupliqué ; un client refusé l'est avec son motif, ligne par ligne.
 *
 * Deux gestes, un seul code :
 *
 * - l'**aperçu** répond ce que le Core ferait, sans rien écrire ;
 * - la **création** écrit, page après page.
 *
 * L'écriture se fait **page par page** : si l'application tombe au milieu
 * de la pagination, les clients déjà écrits restent, et le rejeu est sans
 * risque — la référence est unique par projet (ADR-0133 §5).
 *
 * @phpstan-type Refusal array{line: int, reference: string, reason: string}
 * @phpstan-type Known array{line: int, reference: string}
 * @phpstan-type Report array{
 *     lines: int,
 *     created: int,
 *     alreadyKnown: int,
 *     refused: int,
 *     refusals: list<Refusal>,
 *     known: list<Known>,
 *     interrupted: string|null
 * }
 * @phpstan-type Written array{
 *     lines: int,
 *     created: int,
 *     alreadyKnown: int,
 *     refused: int,
 *     refusals: list<Refusal>,
 *     known: list<Known>
 * }
 */
final readonly class ImportTenantsFromApplication
{
    /**
     * Le nombre de clients demandés par page.
     */
    private const PER_PAGE = 100;

    /**
     * Le nombre de pages que le Core ramène en une fois (5 000 clients).
     */
    private const MAX_PAGES = 50;

    public function __construct(
        private ReadsClientApplication $reader,
        private GetTenantSchema $tenantSchemas,
        private ImportCustomerRows $importRows,
    ) {}

    /**
     * L'aperçu : ce que le Core ferait, sans rien écrire.
     *
     * @return Report
     */
    public function preview(ClientApplicationEndpoint $endpoint, AccessScope $scope, string $actor): array
    {
        $schema = $this->tenantSchemas->accepted($endpoint->projectId, $scope);

        return $this->walk($endpoint, $scope, $schema, $actor, true);
    }

    /**
     * La création : le même chemin d'écriture que l'import en lot.
     *
     * @return Report
     */
    public function import(ClientApplicationEndpoint $endpoint, AccessScope $scope, string $actor): array
    {
        $schema = $this->tenantSchemas->accepted($endpoint->projectId, $scope);

        return $this->walk($endpoint, $scope, $schema, $actor, false);
    }

    /**
     * La lecture de l'application, page après page, et l'écriture de
     * chaque page dès qu'elle arrive.
     *
     * @param TenantSchemaView|null $schema la forme en vigueur
     * @return Report
     */
    private function walk(
        ClientApplicationEndpoint $endpoint,
        AccessScope $scope,
        ?TenantSchemaView $schema,
        string $actor,
        bool $dryRun,
    ): array {
        $columns = CustomerImportTemplate::columnNames($schema);

        $report = [
            'lines' => 0,
            'created' => 0,
            'alreadyKnown' => 0,
            'refused' => 0,
            'refusals' => [],
            'known' => [],
            'interrupted' => null,
        ];

        $page = 1;
        $line = 0;

        while ($page <= self::MAX_PAGES) {
            try {
                $answer = $this->reader->tenants($endpoint, $page, self::PER_PAGE);
            } catch (ClientApplicationReadFailed $exception) {
                // L'application est tombée : ce qui a été écrit reste écrit,
                // et l'opérateur relance — le rejeu est sans risque, la
                // référence est unique par projet.
                $report['interrupted'] = $exception->getMessage();

                break;
            }

            $rows = [];

            foreach ($answer['rows'] as $raw) {
                $line++;
                $rows[] = [
                    'line' => $line,
                    'values' => $this->values($raw, $columns),
                    'malformed' => false,
                ];
            }

            if ($rows !== []) {
                $written = $this->importRows->import(
                    $endpoint->projectId,
                    $rows,
                    $scope,
                    $schema,
                    $actor,
                    $dryRun,
                );

                $report = $this->merge($report, $written);
            }

            if (! $answer['has_more']) {
                break;
            }

            $page++;
        }

        if ($page > self::MAX_PAGES) {
            $report['interrupted'] = 'L\'application a plus de clients que le Core ne ramène en une fois.';
        }

        return $report;
    }

    /**
     * La ligne de l'application, dans les colonnes que le Core attend.
     *
     * Une colonne que l'application n'a pas est **vide** : c'est alors la
     * forme en vigueur qui refuse la ligne, et le motif est dit.
     *
     * @param array<string, mixed> $raw
     * @param list<string> $columns
     * @return array<string, string>
     */
    private function values(array $raw, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $this->text($raw[$column] ?? null);
        }

        return $values;
    }

    /**
     * Une valeur de l'application, ramenée au texte que le Core lit.
     */
    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    /**
     * Le rapport d'une page, ajouté au rapport d'ensemble.
     *
     * @param Report $report
     * @param Written $written
     * @return Report
     */
    private function merge(array $report, array $written): array
    {
        $report['lines'] += $written['lines'];
        $report['created'] += $written['created'];
        $report['alreadyKnown'] += $written['alreadyKnown'];
        $report['refused'] += $written['refused'];
        $report['refusals'] = array_merge($report['refusals'], $written['refusals']);
        $report['known'] = array_merge($report['known'], $written['known']);

        return $report;
    }
}
