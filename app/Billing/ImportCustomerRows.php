<?php

declare(strict_types=1);

namespace App\Billing;

use App\Http\View\TenantSchemaLabels;
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
use Core\Support\Access\AccessScope;

/**
 * ADR-0133 §4 — le chemin d'écriture des clients, **un seul**.
 *
 * Un client entre chez le Core par une ligne : celle d'un fichier importé,
 * ou celle que le Core a tirée de l'application. Les deux empruntent
 * **ce** chemin — la même résolution, les mêmes refus, le même
 * `UpsertCustomer`, le même événement, le même audit. Une seule logique,
 * une seule garantie : un fichier et une liste ne peuvent pas diverger.
 *
 * Ce service ne sait **pas** d'où viennent les lignes : il ne voit que des
 * couples « ligne » et « valeurs ». C'est ce qui permet à l'import en lot
 * et au rattachement de partager le même code, au lieu de partager une
 * intention.
 *
 * Les refus sont **nommés** : la ligne qui n'est pas écrite dit pourquoi,
 * ligne par ligne (ADR-0122 §10).
 */
final class ImportCustomerRows
{
    public function __construct(
        private readonly GetTenantSchema $tenantSchemas,
        private readonly GetCustomer $customers,
        private readonly UpsertCustomer $upsertCustomer,
    ) {}

    /**
     * Une ligne = un client, et un motif pour chaque ligne refusée.
     *
     * `$dryRun` répond la même question **sans rien écrire** : c'est
     * l'aperçu du rattachement — combien seront créés, combien sont déjà
     * connus, combien seront refusés, et pourquoi (ADR-0133 §4). Les
     * refus qui n'apparaissent qu'à l'écriture — une adresse déjà portée
     * par un autre client — ne peuvent pas y figurer : ils apparaîtront
     * dans le rapport.
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
    public function import(
        string $projectId,
        array $rows,
        AccessScope $scope,
        ?TenantSchemaView $schema,
        string $actor,
        bool $dryRun = false,
    ): array {
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

            if (! $dryRun) {
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
                        actor: $actor,
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
}
