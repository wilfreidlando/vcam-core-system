<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Security;

use Carbon\CarbonInterface;
use Core\Identity\Domain\Port\OperatorTokenResolver;
use Core\Identity\Infrastructure\Persistence\Eloquent\OperatorRecord;
use Laravel\Sanctum\PersonalAccessToken;

final class SanctumOperatorTokenResolver implements OperatorTokenResolver
{
    public function resolve(string $plaintextToken): ?string
    {
        $accessToken = PersonalAccessToken::findToken($plaintextToken);

        if ($accessToken === null) {
            return null;
        }

        $expiresAt = $accessToken->getAttribute('expires_at');
        if ($expiresAt instanceof CarbonInterface && $expiresAt->isPast()) {
            return null;
        }

        $tokenable = $accessToken->tokenable;
        if (! $tokenable instanceof OperatorRecord) {
            return null;
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $tokenable->id;
    }
}
