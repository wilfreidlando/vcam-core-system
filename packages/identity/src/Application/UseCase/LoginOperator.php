<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\Dto\OperatorLoginChallenge;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\LoginChallengeCodec;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Support\Clock;

/**
 * Step 1 of the operator login flow: verify email+password, then issue a
 * short-lived challenge token for whichever step comes next — MFA
 * enrollment (first login) or MFA verification (already enrolled). No
 * Sanctum token is issued yet; that only happens once MFA succeeds.
 */
final readonly class LoginOperator
{
    private const int CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private OperatorRepository $operators,
        private LoginChallengeCodec $challenges,
        private Clock $clock,
    ) {}

    public function __invoke(LoginOperatorCommand $command): OperatorLoginChallenge
    {
        $operator = $this->operators->findByEmail($command->email);

        if ($operator === null) {
            // Still pays the Argon2id cost a real verification would —
            // otherwise an unknown email returns near-instantly while a
            // known-email/wrong-password request is measurably slower,
            // letting response timing enumerate which operator emails
            // exist.
            Operator::verifyAgainstDummyHash($command->password);

            throw InvalidOperatorCredentials::unknown();
        }

        if (! $operator->verifyPassword($command->password)) {
            throw InvalidOperatorCredentials::unknown();
        }

        if ($operator->isRevoked()) {
            throw OperatorRevoked::withId($operator->id);
        }

        $purpose = $operator->mfaEnabled() ? 'mfa' : 'mfa_enrollment';
        $expiresAt = $this->clock->now()->modify('+'.self::CHALLENGE_TTL_SECONDS.' seconds');
        $token = $this->challenges->issue($operator->id, $purpose, $expiresAt);

        return new OperatorLoginChallenge(
            purpose: $purpose,
            challengeToken: $token,
            operatorId: $operator->id,
        );
    }
}
