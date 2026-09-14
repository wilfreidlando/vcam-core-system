<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * A port rather than `Str::uuid()` called inline: an aggregate that generates
 * its own identity (the DDD convention this codebase follows — see
 * packages/identity/src/Application/UseCase/CreateOrganization.php) needs
 * that generation to be swappable in tests, the same reason
 * ApiKeySecretGenerator exists.
 */
interface Identifier
{
    public function generate(): string;
}
