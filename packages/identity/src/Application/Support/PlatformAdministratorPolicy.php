<?php

declare(strict_types=1);

namespace Core\Identity\Application\Support;

use Core\Identity\Contracts\CannotChangeOwnPlatformRole;
use Core\Identity\Contracts\LastPlatformAdminDemotion;
use Core\Identity\Domain\Exception\CannotRevokeOwnAccount;
use Core\Identity\Domain\Exception\LastPlatformAdmin;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;

/**
 * Audit 2026-10-29 #6 / G-5 — **one** guard for every path that removes or
 * reduces the privilege of a platform administrator (revocation, role
 * change): the console keeps at least one active `platform_admin`, and an
 * account never revokes or demotes itself.
 *
 * It lives in one place because it is one rule applied by two use cases
 * (`RevokeOperator`, `UpdateOperator`): a door left unguarded would let the
 * console end up with no administrator at all.
 */
final readonly class PlatformAdministratorPolicy
{
    public function __construct(private OperatorRepository $operators) {}

    /**
     * @throws CannotRevokeOwnAccount
     * @throws LastPlatformAdmin
     */
    public function assertCanRevoke(Operator $operator, string $actor): void
    {
        if ($operator->isRevoked()) {
            return;
        }

        if ($this->isActor($operator, $actor)) {
            throw CannotRevokeOwnAccount::forOperator($operator->id);
        }

        if ($this->isLastActivePlatformAdmin($operator)) {
            throw LastPlatformAdmin::forOperator($operator->id);
        }
    }

    /**
     * @throws CannotChangeOwnPlatformRole
     * @throws LastPlatformAdminDemotion
     */
    public function assertCanChangeRole(Operator $operator, ?OperatorRole $newRole, string $actor): void
    {
        if ($newRole === $operator->role) {
            return;
        }

        if ($this->isActor($operator, $actor)) {
            throw CannotChangeOwnPlatformRole::forOperator($operator->id);
        }

        if ($this->isLastActivePlatformAdmin($operator)) {
            throw LastPlatformAdminDemotion::forOperator($operator->id);
        }
    }

    private function isActor(Operator $operator, string $actor): bool
    {
        return $actor === 'operator:'.$operator->id;
    }

    private function isLastActivePlatformAdmin(Operator $operator): bool
    {
        return $operator->role === OperatorRole::PlatformAdmin
            && ! $operator->isRevoked()
            && $this->operators->countActiveWithRole(OperatorRole::PlatformAdmin) <= 1;
    }
}
