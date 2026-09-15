<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Core\Identity\Domain\Port\OperatorTokenRevoker;
use Core\Identity\Infrastructure\Persistence\Eloquent\OperatorRecord;
use Laravel\Sanctum\PersonalAccessToken;

final class SanctumOperatorTokenRevoker implements OperatorTokenRevoker
{
    public function revoke(string $plaintextToken): ?string
    {
        $accessToken = PersonalAccessToken::findToken($plaintextToken);

        if ($accessToken === null) {
            return null;
        }

        $tokenable = $accessToken->tokenable;
        $operatorId = $tokenable instanceof OperatorRecord ? $tokenable->id : null;

        $accessToken->delete();

        return $operatorId;
    }
}
