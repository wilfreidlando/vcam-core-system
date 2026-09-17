<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\OperatorSession;
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
 * Step 3a (first login only): proves the operator can produce a valid
 * code for the secret `EnrollOperatorMfa` just generated, confirms MFA,
 * and — only now — issues the Sanctum access token.
 */
final readonly class ConfirmOperatorMfaEnrollment
{
    public function __construct(
        private OperatorRepository $operators,
        private LoginChallengeCodec $challenges,
        private TotpProvider $totp,
        private OperatorTokenIssuer $tokens,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(ConfirmOperatorMfaEnrollmentCommand $command): OperatorSession
    {
        $operatorId = $this->challenges->verify($command->challengeToken, 'mfa_confirm')
            ?? throw MfaChallengeInvalid::make();

        $operator = $this->operators->find($operatorId)
            ?? throw OperatorNotFound::withId($operatorId);

        if ($operator->isRevoked()) {
            throw OperatorRevoked::withId($operator->id);
        }

        $secret = $operator->mfaSecret()
            ?? throw MfaNotEnrolled::forOperator($operator->id);

        $timestep = $this->totp->verify($secret, $command->code, $operator->mfaLastVerifiedTimestep());
        if ($timestep === false) {
            throw InvalidMfaCode::make();
        }

        $operator->confirmMfa();
        $operator->recordMfaVerification($timestep);
        $this->operators->save($operator);

        $accessToken = $this->tokens->issue($operator->id, 'operator-console');

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->id,
            action: 'operator.mfa_enrolled',
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
