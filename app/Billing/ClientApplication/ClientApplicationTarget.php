<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

use Core\Webhook\Contracts\WebhookEndpointView;

/**
 * ADR-0133 §2 — **ce que le Core sait** de l'application d'un projet.
 *
 * La cible est prête quand le Core sait à qui parler : le point d'entrée
 * qui porte le secret, et l'adresse à appeler. Quand elle n'est pas prête,
 * elle dit **pourquoi** — en français, pour l'opérateur (ADR-0092 §4).
 */
final readonly class ClientApplicationTarget
{
    /**
     * @param list<WebhookEndpointView> $choices les points d'entrée que
     *                                           l'opérateur peut choisir
     */
    public function __construct(
        public string $projectId,
        /** Le point d'entrée qui porte le secret, quand il est connu. */
        public ?string $endpointId,
        /** L'adresse que le Core appelle, quand elle est connue. */
        public ?string $baseUrl,
        public array $choices,
        /** Pourquoi le Core ne peut pas encore parler à l'application. */
        public ?string $reason,
    ) {}

    /**
     * Le Core sait-il à qui parler ?
     */
    public function isReady(): bool
    {
        return $this->endpointId !== null && $this->baseUrl !== null;
    }

    /**
     * Le point d'entrée à appeler, prêt à être utilisé.
     */
    public function endpoint(): ?ClientApplicationEndpoint
    {
        if ($this->endpointId === null || $this->baseUrl === null) {
            return null;
        }

        return new ClientApplicationEndpoint(
            projectId: $this->projectId,
            endpointId: $this->endpointId,
            baseUrl: $this->baseUrl,
        );
    }
}
