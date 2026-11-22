<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use InvalidArgumentException;

/**
 * ADR-0133 §2 — **à qui** le Core parle, et **où**.
 *
 * L'adresse appelée est celle du point d'entrée déjà enregistré pour le
 * projet : même schéma, même hôte, même port. L'opérateur ne saisit pas
 * une seconde adresse ; il peut **corriger** celle-ci, quand l'API de
 * l'application n'est pas sur le même hôte que la réception des
 * événements.
 *
 * Trois cas, et pas un de plus :
 *
 *  1. un branchement a déjà été **retenu** (le point d'entrée choisi, et
 *     l'adresse corrigée s'il y en a une) — c'est lui qui fait foi ;
 *  2. un **seul** point d'entrée actif — l'adresse en est déduite, rien
 *     n'est écrit ;
 *  3. plusieurs points d'entrée actifs — l'opérateur choisit ; le Core ne
 *     devine pas.
 *
 * Aucun point d'entrée, et le Core ne peut pas parler à l'application : il
 * le dit, et n'invente rien.
 */
final readonly class ResolveClientApplicationTarget
{
    public function __construct(
        private ListWebhookEndpoints $endpoints,
        private ClientApplicationConnections $connections,
    ) {}

    /**
     * La cible du projet : ce que le Core sait de son application.
     */
    public function resolve(string $projectId, AccessScope $scope): ClientApplicationTarget
    {
        $views = $this->endpoints->list(new ListWebhookEndpointsCommand($projectId), $scope);

        $active = array_values(array_filter(
            $views,
            static fn (WebhookEndpointView $view): bool => $view->active,
        ));

        $stored = $this->connections->find($projectId);

        if ($stored !== null && $stored->endpointId !== null) {
            foreach ($views as $view) {
                if ($view->id !== $stored->endpointId) {
                    continue;
                }

                $baseUrl = $stored->baseUrl ?? $this->derivedFrom($view->url);

                if ($baseUrl === null) {
                    return new ClientApplicationTarget(
                        projectId: $projectId,
                        endpointId: null,
                        baseUrl: null,
                        choices: $active,
                        reason: 'L\'adresse du point d\'entrée n\'est pas en HTTPS : le Core ne peut pas appeler l\'application.',
                    );
                }

                return new ClientApplicationTarget(
                    projectId: $projectId,
                    endpointId: $view->id,
                    baseUrl: $baseUrl,
                    choices: $this->withChoice($active, $view),
                    reason: null,
                );
            }
        }

        if (count($active) === 1) {
            $baseUrl = $this->derivedFrom($active[0]->url);

            if ($baseUrl === null) {
                return new ClientApplicationTarget(
                    projectId: $projectId,
                    endpointId: null,
                    baseUrl: null,
                    choices: $active,
                    reason: 'L\'adresse du point d\'entrée n\'est pas en HTTPS : le Core ne peut pas appeler l\'application.',
                );
            }

            return new ClientApplicationTarget(
                projectId: $projectId,
                endpointId: $active[0]->id,
                baseUrl: $baseUrl,
                choices: $active,
                reason: null,
            );
        }

        if ($active === []) {
            return new ClientApplicationTarget(
                projectId: $projectId,
                endpointId: null,
                baseUrl: null,
                choices: [],
                reason: 'Enregistrez d\'abord le point d\'entrée des événements de l\'application.',
            );
        }

        return new ClientApplicationTarget(
            projectId: $projectId,
            endpointId: null,
            baseUrl: null,
            choices: $active,
            reason: 'Plusieurs points d\'entrée sont enregistrés : choisissez celui qui reçoit les événements de l\'application.',
        );
    }

    /**
     * Les choix de l'opérateur : les points d'entrée actifs, plus celui qui
     * a été retenu — même désactivé depuis, il reste le point d'entrée du
     * branchement.
     *
     * @param list<WebhookEndpointView> $active
     * @return list<WebhookEndpointView>
     */
    private function withChoice(array $active, WebhookEndpointView $chosen): array
    {
        foreach ($active as $view) {
            if ($view->id === $chosen->id) {
                return $active;
            }
        }

        $active[] = $chosen;

        return $active;
    }

    /**
     * L'adresse déduite du point d'entrée, ou `null` quand elle n'est pas
     * appelable.
     */
    private function derivedFrom(string $endpointUrl): ?string
    {
        try {
            return ClientApplicationEndpoint::baseUrlOf($endpointUrl);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
