<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * The account created by an activation — and the MFA challenge that
 * follows, exactly the pair `POST /v1/operators/login` returns.
 */
final readonly class AcceptedOperatorInvitation
{
    public function __construct(
        public string $operatorId,
        public string $email,
        public ?string $role,
        public bool $accountCreated,
        public string $challengePurpose,
        public string $challengeToken,
    ) {}
}
