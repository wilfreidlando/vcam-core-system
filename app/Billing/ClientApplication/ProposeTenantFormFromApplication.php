<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use Core\Billing\Contracts\ProposeTenantSchema;
use Core\Billing\Contracts\ProposeTenantSchemaCommand;
use Core\Billing\Contracts\TenantSchemaProposal;
use Core\Billing\Domain\Exception\InvalidTenantSchema;

/**
 * ADR-0133 §4 — la forme tirée de l'application devient une **proposition**.
 *
 * Le Core interroge l'application, vérifie ce qu'elle annonce, et le
 * enregistre comme proposition **en attente** : la forme ne change pas
 * sans que l'opérateur l'ait lue et acceptée (ADR-0122 §2). C'est une
 * décision, pas une formalité : la forme dit ce que l'opérateur devra
 * saisir pour chaque client.
 *
 * La vérification est celle de `PUT /v1/projects/{id}/tenant-schema` : la
 * réponse de l'application n'a aucun privilège (ADR-0133 §7). Une
 * déclaration invalide n'est **pas** enregistrée.
 *
 * ADR-0130 §3 — une proposition **identique** à une forme déjà connue ne
 * crée pas de version de plus : le cas d'usage le dit, et la console
 * l'affiche.
 */
final readonly class ProposeTenantFormFromApplication
{
    public function __construct(
        private ReadsClientApplication $reader,
        private ProposeTenantSchema $proposeTenantSchema,
    ) {}

    /**
     * Tire la forme de l'application et la propose.
     *
     * @param string $actor « operator:<uuid> »
     *
     * @throws ClientApplicationReadFailed the application did not answer, or announced a form the Core refuses
     */
    public function propose(ClientApplicationEndpoint $endpoint, string $actor): TenantSchemaProposal
    {
        $form = $this->reader->tenantForm($endpoint);

        try {
            return $this->proposeTenantSchema->propose(new ProposeTenantSchemaCommand(
                projectId: $endpoint->projectId,
                uniqueKey: $form['unique_key'],
                uniqueKeyPattern: $form['unique_key_pattern'],
                fields: $form['fields'],
                actor: $actor,
                correlationId: null,
            ));
        } catch (InvalidTenantSchema $exception) {
            // ADR-0133 §4 — une déclaration invalide n'est pas enregistrée :
            // le motif du refus est dit, rien n'est écrit.
            throw ClientApplicationReadFailed::invalidForm($exception->getMessage());
        }
    }
}
