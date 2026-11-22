<?php

declare(strict_types=1);

namespace App\Billing\ClientApplication;

/**
 * ADR-0133 §2 — le branchement retenu pour un projet : le point d'entrée
 * qui porte le secret, et l'adresse corrigée par l'opérateur — quand il y
 * en a une.
 *
 * Un branchement **sans** correction est simplement l'adresse déduite du
 * point d'entrée : rien n'est alors écrit, et rien n'a besoin de l'être.
 */
final readonly class ClientApplicationConnection
{
    public function __construct(
        public string $projectId,
        public ?string $endpointId,
        public ?string $baseUrl,
    ) {}
}
