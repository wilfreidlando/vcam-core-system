<?php

declare(strict_types=1);

namespace App\Http\View;

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
 */
final class CustomerImportTemplate
{
    /**
     * The name the downloaded file carries.
     */
    public const FILENAME = 'modele-import-clients.csv';

    /**
     * The columns of the imported file, in order.
     *
     * @var list<array{name: string, label: string, explanation: string}>
     */
    private const COLUMNS = [
        [
            'name' => 'external_ref',
            'label' => 'Référence',
            'explanation' => 'La référence du client chez le projet : celle que votre application appelle déjà ce client.',
        ],
        [
            'name' => 'display_name',
            'label' => 'Nom',
            'explanation' => 'Le nom du client, tel qu\'il s\'affichera dans la console.',
        ],
        [
            'name' => 'email',
            'label' => 'E-mail',
            'explanation' => 'L\'adresse électronique du client : elle doit être valide, et libre dans le projet.',
        ],
        [
            'name' => 'phone',
            'label' => 'Téléphone',
            'explanation' => 'Le numéro de téléphone du client : il peut rester vide.',
        ],
    ];

    /**
     * The example line: fictitious values, so that the model is a file
     * the operator can deposit as it is.
     *
     * @var list<string>
     */
    private const EXAMPLE = ['AE0001', 'Auto-école Exemple', 'contact@example.com', '+237600000000'];

    /**
     * The technical names of the columns, in order — the header the
     * reader expects.
     *
     * @return list<string>
     */
    public static function columnNames(): array
    {
        return array_column(self::COLUMNS, 'name');
    }

    /**
     * What each column is, in the words of the operator.
     *
     * @return list<array{label: string, explanation: string}>
     */
    public static function columns(): array
    {
        $columns = [];

        foreach (self::COLUMNS as $column) {
            $columns[] = [
                'label' => $column['label'],
                'explanation' => $column['explanation'],
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
    public static function content(): string
    {
        $lines = [
            implode(',', self::columnNames()),
            implode(',', self::EXAMPLE),
        ];

        return implode("\n", $lines)."\n";
    }
}
