<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

/**
 * ADR-0133 §1 — la lecture d'une application cliente.
 *
 * Deux lectures, et deux seulement :
 *
 * - la **forme** que l'application veut pour son locataire ;
 * - les **locataires** qu'elle a déjà.
 *
 * Ce port est la seule porte par laquelle le Core entre chez une
 * application cliente. Il ne sait rien du réseau : l'implémentation HTTP
 * passe par `Core\Support\Integration` (G6c), et la signature par le
 * secret du point d'entrée (ADR-0133 §3).
 */
interface ReadsClientApplication
{
    /**
     * La forme que l'application veut.
     *
     * La charge utile est **exactement** celle de
     * `PUT /v1/projects/{id}/tenant-schema` : la même clé unique, le même
     * motif, les mêmes champs (ADR-0133 §4). Le Core la valide par les
     * règles qu'il applique à cette route — une réponse d'application
     * n'a aucun privilège.
     *
     * @return array{
     *     unique_key: string,
     *     unique_key_pattern: string,
     *     fields: list<array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}>
     * }
     *
     * @throws ClientApplicationReadFailed the application did not answer, or answered something the Core cannot read
     */
    public function tenantForm(ClientApplicationEndpoint $endpoint): array;

    /**
     * Les locataires qu'elle a déjà, une page.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     *
     * @throws ClientApplicationReadFailed the application did not answer, or answered something the Core cannot read
     */
    public function tenants(ClientApplicationEndpoint $endpoint, int $page, int $perPage): array;
}
