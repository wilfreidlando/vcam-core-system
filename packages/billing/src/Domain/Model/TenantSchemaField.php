<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidTenantSchema;

/**
 * ADR-0122 §1, §3 — une **clé** d'attribut déclaré par l'application.
 *
 * Le Core ne sait pas ce qu'est une auto-école : il sait qu'une valeur
 * s'appelle `localisation`, qu'elle est un texte, qu'elle est obligatoire
 * et qu'elle vient de la saisie de l'opérateur. C'est une **forme**, jamais
 * une valeur.
 *
 * Les règles :
 *
 * - la clé est un nom technique court et stable (`^[a-z][a-z0-9_]{0,63}$`) ;
 * - le libellé est ce que l'opérateur lit — il est donc requis ;
 * - le type est un des dix que le Core connaît (`TenantSchemaFieldType`) ;
 * - la source dit **d'où vient la valeur** : de la saisie de l'opérateur
 *   (`declared.localisation`), ou de l'identité du Core
 *   (`core_identity.email`). Une source du Core est acceptée quand elle
 *   nomme un champ que le Core connaît : l'application **demande** alors
 *   cette donnée, et la clé déclarée **remplace** le champ du Core dans le
 *   formulaire — l'opérateur ne saisit jamais la même valeur deux fois
 *   (ADR-0122 §5) ;
 * - la longueur maximale est bornée : une valeur d'exploitation, pas un
 *   texte libre.
 */
final readonly class TenantSchemaField
{
    /**
     * La longueur maximale d'un libellé.
     */
    public const MAX_LABEL_LENGTH = 120;

    /**
     * La longueur maximale qu'un champ peut déclarer.
     */
    public const MAX_LENGTH_BOUND = 4096;

    /**
     * L'espace de noms des sources que le Core sert (ADR-0122 §5).
     */
    public const RESERVED_SOURCE_NAMESPACE = 'core_identity';

    /**
     * Les sources que le Core sait servir : l'application demande une de
     * ces valeurs, et le Core la lui donne.
     *
     * @var list<string>
     */
    public const CORE_SOURCES = [
        'core_identity.display_name',
        'core_identity.email',
        'core_identity.phone',
        'core_identity.locale',
    ];

    public function __construct(
        public string $key,
        public string $label,
        public TenantSchemaFieldType $type,
        public bool $required,
        public ?int $maxLength,
        public string $source,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            throw InvalidTenantSchema::becauseBadFieldKey($key);
        }

        $trimmedLabel = trim($label);

        if ($trimmedLabel === '' || mb_strlen($trimmedLabel) > self::MAX_LABEL_LENGTH) {
            throw InvalidTenantSchema::becauseBadLabel($label);
        }

        if ($maxLength !== null && ($maxLength < 1 || $maxLength > self::MAX_LENGTH_BOUND)) {
            throw InvalidTenantSchema::becauseBadMaxLength($maxLength);
        }

        if (trim($source) === '' || mb_strlen($source) > 128) {
            throw InvalidTenantSchema::becauseBadLabel($source);
        }

        if (self::isUnknownCoreSource($source)) {
            throw InvalidTenantSchema::becauseUnknownCoreSource($source);
        }
    }

    /**
     * Un champ déclaré, depuis sa forme JSON.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidTenantSchema le type est inconnu ou la forme est fausse
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $type = is_string($type) ? $type : '';

        $maxLength = $data['max_length'] ?? null;
        $required = $data['required'] ?? false;

        return new self(
            key: is_string($data['key'] ?? null) ? $data['key'] : '',
            label: is_string($data['label'] ?? null) ? $data['label'] : '',
            type: TenantSchemaFieldType::tryFrom($type) ?? throw InvalidTenantSchema::becauseUnknownType($type),
            required: (bool) $required,
            maxLength: is_int($maxLength) ? $maxLength : (is_numeric($maxLength) ? (int) $maxLength : null),
            source: is_string($data['source'] ?? null) ? $data['source'] : '',
        );
    }

    /**
     * La forme JSON du champ — celle que porte la colonne `fields`.
     *
     * @return array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'max_length' => $this->maxLength,
            'source' => $this->source,
        ];
    }

    /**
     * La source nomme-t-elle un champ du Core que le Core ne connaît pas ?
     *
     * `core_identity.email` est acceptée : l'application demande l'e-mail
     * du client, et la clé déclarée remplace le champ du Core dans le
     * formulaire. `core_identity.whatever` ne l'est pas : elle ne dit pas
     * quelle valeur l'application veut (ADR-0122 §5).
     */
    private static function isUnknownCoreSource(string $source): bool
    {
        $trimmed = trim($source);

        if ($trimmed === self::RESERVED_SOURCE_NAMESPACE) {
            return true;
        }

        if (! str_starts_with($trimmed, self::RESERVED_SOURCE_NAMESPACE.'.')) {
            return false;
        }

        return ! in_array($trimmed, self::CORE_SOURCES, true);
    }
}
