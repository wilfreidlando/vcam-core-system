<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Infrastructure\Persistence\Eloquent\OperatorRecord;

final class SanctumOperatorTokenIssuer implements OperatorTokenIssuer
{
    public function issue(string $operatorId, string $tokenName): string
    {
        $record = OperatorRecord::query()->find($operatorId)
            ?? throw OperatorNotFound::withId($operatorId);

        return $record->createToken($tokenName)->plainTextToken;
    }
}
