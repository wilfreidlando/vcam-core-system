<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\TenantSchemaViews;
use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Contracts\TenantSchemaView;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use Core\Support\Access\AccessScope;

/**
 * ADR-0122 §2, §6 — la déclaration de forme **en force** d'un projet.
 *
 * Le port est un port de lecture que la console appelle : le `AccessScope`
 * est donc un paramètre requis (G16). Un projet que le scope ne voit pas
 * n'a pas de déclaration : le Core ne dit pas ce qu'il cache ailleurs.
 */
final readonly class GetTenantSchemaUseCase implements GetTenantSchema
{
    public function __construct(
        private TenantSchemaRepository $schemas,
    ) {}

    public function accepted(string $projectId, AccessScope $scope): ?TenantSchemaView
    {
        if (! $scope->allowsProject($projectId)) {
            return null;
        }

        $schema = $this->schemas->findAccepted($projectId);

        if ($schema === null) {
            return null;
        }

        return TenantSchemaViews::from($schema);
    }

    /**
     * The declaration **waiting to be read** — the one the operator has
     * to accept or refuse (ADR-0122 §2).
     *
     * The repository answers the **last** pending declaration of the
     * project, the most recent first: an older proposal has been
     * superseded by a newer one and never comes back.
     */
    public function pending(string $projectId, AccessScope $scope): ?TenantSchemaView
    {
        if (! $scope->allowsProject($projectId)) {
            return null;
        }

        $schema = $this->schemas->findPending($projectId);

        if ($schema === null) {
            return null;
        }

        return TenantSchemaViews::from($schema);
    }

    public function acceptsReference(string $projectId, string $reference, AccessScope $scope): bool
    {
        if (! $scope->allowsProject($projectId)) {
            return false;
        }

        $schema = $this->schemas->findAccepted($projectId);

        // Sans déclaration acceptée, le Core n'a rien à opposer à une
        // référence : il ne refuse rien (ADR-0122 §6).
        if ($schema === null) {
            return true;
        }

        return $schema->uniqueKeyPattern->matches($reference);
    }
}
