<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\TenantSchemaViews;
use Core\Billing\Contracts\ProposeTenantSchema;
use Core\Billing\Contracts\ProposeTenantSchemaCommand;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Exception\InvalidTenantSchema;
use Core\Billing\Domain\Model\TenantSchema;
use Core\Billing\Domain\Model\TenantSchemaField;
use Core\Billing\Domain\Model\TenantSchemaFieldType;
use Core\Billing\Domain\Model\TenantSchemaPattern;
use Core\Billing\Domain\Model\TenantSchemaStatus;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0122 §2 — l'application **propose** la forme de son locataire.
 *
 * Une proposition est une **version** : elle porte le numéro suivant, elle
 * attend la lecture de l'opérateur, et elle ne touche pas à la forme en
 * force. Une proposition plus récente remplace la précédente : l'opérateur
 * lit la dernière, pas une file de formes mortes.
 *
 * Le geste ne déplace aucun argent et n'écrit aucun client : il décrit une
 * forme (ADR-0122 §8).
 */
final readonly class ProposeTenantSchemaUseCase implements ProposeTenantSchema
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private TenantSchemaRepository $schemas,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function propose(ProposeTenantSchemaCommand $command): TenantSchemaView
    {
        return $this->unitOfWork->transactional(function () use ($command): TenantSchemaView {
            $now = $this->clock->now();

            // La proposition en attente qu'une plus récente remplace sort de
            // l'attente de lecture : l'opérateur lit la dernière.
            $pending = $this->schemas->findPending($command->projectId);

            if ($pending !== null) {
                $pending->supersede($now);
                $this->schemas->save($pending);
            }

            $schema = new TenantSchema(
                id: $this->identifier->generate(),
                projectId: $command->projectId,
                version: $this->schemas->highestVersion($command->projectId) + 1,
                status: TenantSchemaStatus::Pending,
                uniqueKey: $command->uniqueKey,
                uniqueKeyPattern: TenantSchemaPattern::of($command->uniqueKeyPattern),
                fields: $this->fields($command->fields),
                acceptedAt: null,
                createdAt: $now,
                updatedAt: $now,
            );

            $this->schemas->save($schema);

            return TenantSchemaViews::from($schema);
        });
    }

    /**
     * Les champs déclarés, en objets du domaine : le domaine garde ses
     * règles, la commande ne porte que des noms (ADR-0122 §1).
     *
     * @param list<array{key: string, label: string, type: string, required: bool, max_length?: int|null, source: string}> $fields
     * @return list<TenantSchemaField>
     */
    private function fields(array $fields): array
    {
        $mapped = [];

        foreach ($fields as $field) {
            $mapped[] = new TenantSchemaField(
                key: $field['key'],
                label: $field['label'],
                type: TenantSchemaFieldType::tryFrom($field['type'])
                    ?? throw InvalidTenantSchema::becauseUnknownType($field['type']),
                required: $field['required'],
                // La longueur maximale est facultative : une clé qui ne la
                // porte pas est une clé sans borne, pas une clé invalide.
                maxLength: $field['max_length'] ?? null,
                source: $field['source'],
            );
        }

        return $mapped;
    }
}
