<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\TenantSchemaViews;
use Core\Billing\Contracts\AcceptTenantSchema;
use Core\Billing\Contracts\AcceptTenantSchemaCommand;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Exception\TenantSchemaNotFound;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0122 §2 — l'opérateur **accepte** la déclaration proposée.
 *
 * L'acceptation met la forme en force. La déclaration qui l'était avant
 * sort : **une seule** reste acceptée par projet — c'est aussi ce que
 * l'index partiel de la base garantit (ADR-0122 §2).
 */
final readonly class AcceptTenantSchemaUseCase implements AcceptTenantSchema
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private TenantSchemaRepository $schemas,
        private Clock $clock,
    ) {}

    public function accept(AcceptTenantSchemaCommand $command): TenantSchemaView
    {
        return $this->unitOfWork->transactional(function () use ($command): TenantSchemaView {
            $now = $this->clock->now();

            $schema = $this->schemas->findById($command->projectId, $command->schemaId);

            if ($schema === null) {
                throw TenantSchemaNotFound::forProject($command->projectId, $command->schemaId);
            }

            // La déclaration qui était en force sort de la force : la
            // nouvelle la remplace, jamais ne s'y ajoute.
            $previous = $this->schemas->findAccepted($command->projectId);

            if ($previous !== null && $previous->id !== $schema->id) {
                $previous->supersede($now);
                $this->schemas->save($previous);
            }

            $schema->accept($now);
            $this->schemas->save($schema);

            return TenantSchemaViews::from($schema);
        });
    }
}
