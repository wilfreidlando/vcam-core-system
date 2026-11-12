<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Requests;

use Core\Billing\Domain\Model\TenantSchemaFieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /v1/projects/{projectId}/tenant-schema` — ADR-0122 §2.
 *
 * L'application propose la **forme** de son locataire : une clé unique, le
 * motif que doit respecter la référence du Core, et la liste des clés
 * d'attribut. Ce sont des noms, jamais des valeurs (ADR-0122 §1).
 */
final class ProposeTenantSchemaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The scope is checked by the controller (`tenant_schema:write`):
        // the request is valid on its own terms, the caller is the one that
        // may or may not use it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * L'attribut que l'application désigne : celui qui identifie un
             * locataire **chez elle**. C'est un nom technique, jamais une
             * valeur.
             *
             * @example "code_etablissement"
             */
            'unique_key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            /**
             * Le motif que doit respecter la référence du Core pour
             * devenir cette clé. Le Core refuse un motif qui pourrait
             * autoriser une référence hors de son alphabet — il ne valide
             * que la forme ici (ADR-0122 §6).
             *
             * @example "AE-[0-9]{4}"
             */
            'unique_key_pattern' => ['required', 'string', 'max:256'],
            /**
             * Les clés d'attribut déclarées : au plus 32 (ADR-0122 §1).
             *
             * @example [{"key":"localisation","label":"Localisation","type":"string","required":true,"max_length":120,"source":"operator"}]
             */
            'fields' => ['required', 'array', 'min:1', 'max:32'],
            /**
             * Le nom de l'attribut, chez l'application.
             *
             * @example "localisation"
             */
            'fields.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            /**
             * Ce que l'opérateur lit dans le formulaire.
             *
             * @example "Localisation"
             */
            'fields.*.label' => ['required', 'string', 'max:120'],
            /**
             * Le type de la valeur — le Core n'en connaît que dix
             * (ADR-0122 §3).
             *
             * @example "string"
             */
            'fields.*.type' => ['required', 'string', Rule::in(array_map(
                static fn (TenantSchemaFieldType $type): string => $type->value,
                TenantSchemaFieldType::cases(),
            ))],
            /**
             * La valeur est-elle obligatoire ? Une clé obligatoire ne peut
             * pas être enregistrée vide (ADR-0122 §1).
             *
             * @example true
             */
            'fields.*.required' => ['required', 'boolean'],
            /**
             * La longueur maximale de la valeur, en caractères.
             *
             * @example 120
             */
            'fields.*.max_length' => ['nullable', 'integer', 'min:1', 'max:4096'],
            /**
             * D'où vient la valeur. Deux familles :
             *
             * - `declared.<clé>` : la valeur est celle que l'opérateur saisit
             *   pour cette clé ;
             * - `core_identity.<champ>` : la valeur est celle de l'identité du
             *   Core — l'application **demande** cette donnée. Le champ est
             *   alors remplacé dans le formulaire par la clé déclarée :
             *   l'opérateur ne saisit jamais la même valeur deux fois
             *   (ADR-0122 §5).
             *
             * Le domaine refuse une source du Core qui nommerait un champ
             * inconnu.
             *
             * @example "declared.localisation"
             */
            'fields.*.source' => ['required', 'string', 'max:128'],
        ];
    }
}
