<?php

declare(strict_types=1);

namespace App\Http\View;

use Core\Billing\Contracts\TenantSchemaFieldView;
use Core\Billing\Contracts\TenantSchemaView;

/**
 * The file the operator imports: its columns, and the model he downloads.
 *
 * The header is the **contract** between the client application and the
 * Core: the reader refuses any file whose first line does not carry
 * exactly that list of names (ADR-0122 §9). The model is therefore not a
 * convenience — it is the one place where that contract is written down,
 * and the operator must never have to guess a column name.
 *
 * Hence **one source**: the reader of the file and the download of the
 * model read this class, and this class alone. A column added here is
 * added everywhere; a column forgotten here is refused everywhere.
 *
 * Two vocabularies live here, and they must not be mixed:
 *
 *  - the **name** of a column (`external_ref`…) is the technical name
 *    the file carries — the contract of the format. It belongs to the
 *    CSV file, never to a sentence the operator reads;
 *  - the **label** and the **explanation** are what the operator reads:
 *    « Référence », « Nom », « E-mail », « Téléphone », in the words of
 *    the activity.
 *
 * **The form in force drives the form of the file.** A project that
 * declared the shape of its customers imports a file that carries that
 * shape: one column per declared key, and the Core's own identity
 * columns only when the declaration does not replace them (ADR-0122 §5).
 * Without a declaration in force, the file keeps the four columns the
 * Core has always read.
 */
final class CustomerImportTemplate
{
    /**
     * The name the downloaded file carries.
     */
    public const FILENAME = 'modele-import-clients.csv';

    /**
     * The Core's own columns, in order.
     *
     * `identity` names the Core field the column feeds — `null` when the
     * column is the reference, which is the key and never a value. A
     * declaration that replaces that field takes its place in the file:
     * the operator types the value once (ADR-0122 §5).
     *
     * @var list<array{name: string, label: string, explanation: string, identity: ?string, example: string}>
     */
    private const CORE_COLUMNS = [
        [
            'name' => 'external_ref',
            'label' => 'Référence',
            'explanation' => 'La référence du client chez le projet : celle que votre application appelle déjà ce client.',
            'identity' => null,
            'example' => 'AE0001',
        ],
        [
            'name' => 'display_name',
            'label' => 'Nom',
            'explanation' => 'Le nom du client, tel qu\'il s\'affichera dans la console.',
            'identity' => 'display_name',
            'example' => 'Auto-école Exemple',
        ],
        [
            'name' => 'email',
            'label' => 'E-mail',
            'explanation' => 'L\'adresse électronique du client : elle doit être valide, et libre dans le projet.',
            'identity' => 'email',
            'example' => 'contact@example.com',
        ],
        [
            'name' => 'phone',
            'label' => 'Téléphone',
            'explanation' => 'Le numéro de téléphone du client : il peut rester vide.',
            'identity' => 'phone',
            'example' => '+237600000000',
        ],
    ];

    /**
     * The technical names of the columns, in order — the header the
     * reader expects.
     *
     * @return list<string>
     */
    public static function columnNames(?TenantSchemaView $schema = null): array
    {
        return array_column(self::definitions($schema), 'name');
    }

    /**
     * What each column is, in the words of the operator.
     *
     * @return list<array{name: string, label: string, explanation: string}>
     */
    public static function columns(?TenantSchemaView $schema = null): array
    {
        $columns = [];

        foreach (self::definitions($schema) as $definition) {
            $columns[] = [
                'name' => $definition['name'],
                'label' => $definition['label'],
                'explanation' => $definition['explanation'],
            ];
        }

        return $columns;
    }

    /**
     * The content of the model: the header, then an example line.
     *
     * No byte-order mark — the reader takes the first cell as it is, and
     * a mark would make the header another one. A plain line ending, and
     * the comma as the only separator.
     */
    public static function content(?TenantSchemaView $schema = null): string
    {
        $definitions = self::definitions($schema);

        $header = [];
        $example = [];

        foreach ($definitions as $definition) {
            $header[] = self::cell($definition['name']);
            $example[] = self::cell($definition['example']);
        }

        $lines = [
            implode(',', $header),
            implode(',', $example),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * The columns of the file, in order, with everything the model and
     * the reader need.
     *
     * The order never changes for the Core's own columns: the reference
     * first, then the identity fields that remain. The declared keys come
     * last, in the order of the declaration — the application adds its
     * own values to a file whose beginning is always the same.
     *
     * @return list<array{name: string, label: string, explanation: string, example: string}>
     */
    private static function definitions(?TenantSchemaView $schema): array
    {
        $replaced = TenantSchemaLabels::replacedIdentityFields($schema);

        $definitions = [];

        foreach (self::CORE_COLUMNS as $column) {
            if ($column['identity'] !== null && isset($replaced[$column['identity']])) {
                // The declaration carries this value: the Core's column
                // would ask the operator for it twice.
                continue;
            }

            $definitions[] = [
                'name' => $column['name'],
                'label' => $column['label'],
                'explanation' => $column['explanation'],
                'example' => $column['example'],
            ];
        }

        foreach (self::declaredColumns($schema) as $column) {
            $definitions[] = $column;
        }

        return $definitions;
    }

    /**
     * The columns the declaration in force brings.
     *
     * @return list<array{name: string, label: string, explanation: string, example: string}>
     */
    private static function declaredColumns(?TenantSchemaView $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $columns = [];

        foreach ($schema->fields as $field) {
            $columns[] = [
                'name' => $field->key,
                'label' => $field->label,
                'explanation' => self::explanation($field),
                'example' => self::example($field),
            ];
        }

        return $columns;
    }

    /**
     * What a declared column is, in the words of the operator.
     */
    private static function explanation(TenantSchemaFieldView $field): string
    {
        $identity = TenantSchemaLabels::identityField($field->source);

        if ($identity !== null) {
            // The declaration takes the place of the Core's field: the
            // column says the same thing, in the words of the
            // application.
            return match ($identity) {
                'display_name' => 'Le nom du client, tel qu\'il s\'affichera dans la console.',
                'email' => 'L\'adresse électronique du client : elle doit être valide, et libre dans le projet.',
                'phone' => 'Le numéro de téléphone du client : il peut rester vide.',
                'locale' => 'La langue du client : « fr » par défaut.',
                default => 'La valeur que l\'application déclare pour ce client.',
            };
        }

        $type = TenantSchemaLabels::type($field->type);

        if ($field->required) {
            return sprintf(
                'La valeur que l\'application déclare pour « %s » (%s) : elle est obligatoire.',
                $field->label,
                $type,
            );
        }

        return sprintf(
            'La valeur que l\'application déclare pour « %s » (%s) : elle peut rester vide.',
            $field->label,
            $type,
        );
    }

    /**
     * The example value of a declared column: a value the field can hold.
     */
    private static function example(TenantSchemaFieldView $field): string
    {
        $identity = TenantSchemaLabels::identityField($field->source);

        if ($identity !== null) {
            return match ($identity) {
                'display_name' => 'Auto-école Exemple',
                'email' => 'contact@example.com',
                'phone' => '+237600000000',
                'locale' => 'fr',
                default => 'Exemple',
            };
        }

        return match ($field->type) {
            'integer' => '1',
            'decimal' => '1.5',
            'boolean' => 'oui',
            'date' => '2026-01-01',
            'datetime' => '2026-01-01T09:00',
            'email' => 'contact@example.com',
            'phone' => '+237600000000',
            'url' => 'https://example.com',
            default => 'Exemple',
        };
    }

    /**
     * A cell of the file: quoted when it carries a separator, so that the
     * reader reads back exactly what was written.
     */
    private static function cell(string $value): string
    {
        if (! str_contains($value, ',') && ! str_contains($value, '"') && ! str_contains($value, "\n")) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
