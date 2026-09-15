<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\OperatorSession;
use Core\Identity\Application\Dto\VerifyOperatorMfaCommand;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Domain\Port\LoginChallengeCodec;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Domain\Port\TotpProvider;
use Core\Support\Clock;

/**
 * Step 2b (returning login, MFA already enabled): verifies the TOTP code
 * against the enrolled secret and issues the Sanctum access token.
 */
final readonly class VerifyOperatorMfa
{
    public function __construct(
        private OperatorRepository $operators,
        private LoginChallengeCodec $challenges,
        private TotpProvider $totp,
        private OperatorTokenIssuer $tokens,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(VerifyOperatorMfaCommand $command): OperatorSession
    {
        $operatorId = $this->challenges->verify($command->challengeToken, 'mfa')
            ?? throw MfaChallengeInvalid::make();

        $operator = $this->operators->find($operatorId)
            ?? throw OperatorNotFound::withId($operatorId);

        if ($operator->isRevoked()) {
            throw OperatorRevoked::withId($operator->id);
        }

        $secret = $operator->mfaSecret()
            ?? throw MfaNotEnrolled::forOperator($operator->id);

        if (! $this->totp->verify($secret, $command->code)) {
            throw InvalidMfaCode::make();
        }

        $accessToken = $this->tokens->issue($operator->id, 'operator-console');

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->id,
            action: 'operator.login_succeeded',
            resource: 'operator:'.$operator->id,
            before: null,
            after: null,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return new OperatorSession($operator->id, $accessToken);
    }
}
