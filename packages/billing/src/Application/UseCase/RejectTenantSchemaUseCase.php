<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\TenantSchemaViews;
use Core\Billing\Contracts\RejectTenantSchema;
use Core\Billing\Contracts\RejectTenantSchemaCommand;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Exception\TenantSchemaNotFound;
use Core\Billing\Domain\Exception\TenantSchemaNotPending;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0122 §2 — l'opérateur **refuse** la déclaration proposée.
 *
 * Le refus est visible : la déclaration sort de l'attente de lecture et
 * ne sera jamais rendue. Seule une déclaration encore en attente peut
 * être refusée — une forme en force ne se retire pas par un refus : elle
 * se remplace (ADR-0122 §2).
 */
final readonly class RejectTenantSchemaUseCase implements RejectTenantSchema
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private TenantSchemaRepository $schemas,
        private Clock $clock,
    ) {}

    public function reject(RejectTenantSchemaCommand $command): TenantSchemaView
    {
        return $this->unitOfWork->transactional(function () use ($command): TenantSchemaView {
            $now = $this->clock->now();

            $schema = $this->schemas->findById($command->projectId, $command->schemaId);

            if ($schema === null) {
                throw TenantSchemaNotFound::forProject($command->projectId, $command->schemaId);
            }

            if (! $schema->isPending()) {
                throw TenantSchemaNotPending::becauseStatus($schema->status->value);
            }

            $schema->supersede($now);
            $this->schemas->save($schema);

            return TenantSchemaViews::from($schema);
        });
    }
}
