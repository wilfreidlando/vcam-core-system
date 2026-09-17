<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\OperatorMfaEnrollment;
use Core\Identity\Domain\Exception\MfaAlreadyEnrolled;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Domain\Port\LoginChallengeCodec;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\TotpProvider;
use Core\Support\Clock;

/**
 * Step 2a (first login only): generates a TOTP secret and hands back the
 * provisioning URI plus a fresh challenge for the confirmation step.
 * MFA is not yet `enabled` — that happens only once `ConfirmOperatorMfaEnrollment`
 * proves the operator can produce a valid code.
 */
final readonly class EnrollOperatorMfa
{
    private const int CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private OperatorRepository $operators,
        private LoginChallengeCodec $challenges,
        private TotpProvider $totp,
        private Clock $clock,
    ) {}

    public function __invoke(EnrollOperatorMfaCommand $command): OperatorMfaEnrollment
    {
        $operatorId = $this->challenges->verify($command->challengeToken, 'mfa_enrollment')
            ?? throw MfaChallengeInvalid::make();

        $operator = $this->operators->find($operatorId)
            ?? throw OperatorNotFound::withId($operatorId);

        if ($operator->isRevoked()) {
            throw OperatorRevoked::withId($operator->id);
        }

        if ($operator->mfaEnabled()) {
            throw MfaAlreadyEnrolled::forOperator($operator->id);
        }

        $secret = $this->totp->generateSecret();
        $operator->enrollMfa($secret);
        $this->operators->save($operator);

        $expiresAt = $this->clock->now()->modify('+'.self::CHALLENGE_TTL_SECONDS.' seconds');
        $confirmToken = $this->challenges->issue($operator->id, 'mfa_confirm', $expiresAt);

        return new OperatorMfaEnrollment(
            secret: $secret,
            otpauthUri: $this->totp->buildOtpauthUri($secret, $operator->email),
            confirmChallengeToken: $confirmToken,
        );
    }
}
