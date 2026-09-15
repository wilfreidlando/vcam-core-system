<?php

declare(strict_types=1);

use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\CreateOperatorCommand;
use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\Dto\VerifyOperatorMfaCommand;
use Core\Identity\Application\UseCase\ConfirmOperatorMfaEnrollment;
use Core\Identity\Application\UseCase\CreateOperator;
use Core\Identity\Application\UseCase\EnrollOperatorMfa;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Application\UseCase\ResolveOperatorFromBearerToken;
use Core\Identity\Application\UseCase\VerifyOperatorMfa;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\MfaAlreadyEnrolled;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\FakeOperatorTokenIssuer;
use Core\Identity\Tests\Unit\Fakes\FakeOperatorTokenResolver;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\FixedTotpProvider;
use Core\Identity\Tests\Unit\Fakes\InMemoryLoginChallengeCodec;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;

final readonly class OperatorAuthFlowFixture
{
    public function __construct(
        public CreateOperator $createOperator,
        public LoginOperator $loginOperator,
        public EnrollOperatorMfa $enrollOperatorMfa,
        public ConfirmOperatorMfaEnrollment $confirmOperatorMfaEnrollment,
        public VerifyOperatorMfa $verifyOperatorMfa,
        public ResolveOperatorFromBearerToken $resolveOperatorFromBearerToken,
        public InMemoryOperatorRepository $operators,
        public FakeOperatorTokenIssuer $tokenIssuer,
        public FakeOperatorTokenResolver $tokenResolver,
        public RecordingAuditLogger $audit,
        public InMemoryLoginChallengeCodec $challenges,
        public FixedClock $clock,
    ) {}

    public static function make(): self
    {
        $operators = new InMemoryOperatorRepository;
        $challenges = new InMemoryLoginChallengeCodec;
        $totp = new FixedTotpProvider;
        $tokenIssuer = new FakeOperatorTokenIssuer;
        $tokenResolver = new FakeOperatorTokenResolver;
        $audit = new RecordingAuditLogger;
        $clock = new FixedClock;

        return new self(
            createOperator: new CreateOperator($operators, new SequentialIdentifier, $audit, $clock),
            loginOperator: new LoginOperator($operators, $challenges, $clock),
            enrollOperatorMfa: new EnrollOperatorMfa($operators, $challenges, $totp, $clock),
            confirmOperatorMfaEnrollment: new ConfirmOperatorMfaEnrollment($operators, $challenges, $totp, $tokenIssuer, $audit, $clock),
            verifyOperatorMfa: new VerifyOperatorMfa($operators, $challenges, $totp, $tokenIssuer, $audit, $clock),
            resolveOperatorFromBearerToken: new ResolveOperatorFromBearerToken($tokenResolver, $operators),
            operators: $operators,
            tokenIssuer: $tokenIssuer,
            tokenResolver: $tokenResolver,
            audit: $audit,
            challenges: $challenges,
            clock: $clock,
        );
    }
}

it('walks an operator through first login: create, login, enroll mfa, confirm, resolve', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    $created = ($fixture->createOperator)(
        new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::SupportOperator),
        actor: 'service:cli',
        correlationId: 'corr-1',
    );

    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    expect($challenge->purpose)->toBe('mfa_enrollment');

    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));
    expect($enrollment->secret)->not->toBeEmpty()
        ->and($enrollment->otpauthUri)->toContain('ops@example.com');

    $session = ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );
    expect($session->operatorId)->toBe($created->operatorId)
        ->and($session->accessToken)->not->toBeEmpty();

    $operator = $fixture->operators->find($created->operatorId);
    assert($operator instanceof Operator);
    expect($operator->mfaEnabled())->toBeTrue();

    // Wire the issued token into the resolver fake, then confirm it resolves
    // back to the same operator — this is what ResolveOperatorMiddleware
    // does on every subsequent admin-console request.
    $fixture->tokenResolver->register($session->accessToken, $created->operatorId);
    $context = ($fixture->resolveOperatorFromBearerToken)($session->accessToken);

    expect($context->operatorId)->toBe($created->operatorId)
        ->and($context->role)->toBe(OperatorRole::SupportOperator);

    $actions = array_map(static fn ($entry) => $entry->action, $fixture->audit->entries);
    expect($actions)->toContain('operator.created')
        ->and($actions)->toContain('operator.mfa_enrolled');
});

it('returning login skips straight to mfa verification once already enrolled', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    $created = ($fixture->createOperator)(
        new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::FinanceOperator),
        actor: 'service:cli',
        correlationId: null,
    );

    $firstChallenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($firstChallenge->challengeToken));
    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );

    $secondChallenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    expect($secondChallenge->purpose)->toBe('mfa');

    $session = ($fixture->verifyOperatorMfa)(
        new VerifyOperatorMfaCommand($secondChallenge->challengeToken, FixedTotpProvider::VALID_CODE),
    );
    expect($session->operatorId)->toBe($created->operatorId);
});

it('rejects a wrong password without revealing whether the email exists', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    ($fixture->createOperator)(
        new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::SupportOperator),
        actor: 'service:cli',
        correlationId: null,
    );

    ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', 'wrong-password'));
})->throws(InvalidOperatorCredentials::class);

it('rejects an unknown email the same way as a wrong password', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    ($fixture->loginOperator)(new LoginOperatorCommand('nobody@example.com', 'whatever'));
})->throws(InvalidOperatorCredentials::class);

it('rejects an invalid mfa code during confirmation', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $created = ($fixture->createOperator)(
        new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::SupportOperator),
        actor: 'service:cli',
        correlationId: null,
    );
    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));

    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, 'wrong-code'),
    );
})->throws(InvalidMfaCode::class);

it('rejects enrolling mfa twice', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $created = ($fixture->createOperator)(
        new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::SupportOperator),
        actor: 'service:cli',
        correlationId: null,
    );
    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));
    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );

    // A fresh enrollment-purpose challenge issued for an operator whose
    // MFA is already confirmed — as if the first-login step were replayed.
    $replayedChallenge = $fixture->challenges->issue($created->operatorId, 'mfa_enrollment', $fixture->clock->now());
    ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($replayedChallenge));
})->throws(MfaAlreadyEnrolled::class);

it('rejects a stale mfa_enrollment challenge presented after mfa is already enabled', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $created = ($fixture->createOperator)(
        new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::SupportOperator),
        actor: 'service:cli',
        correlationId: null,
    );
    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));
    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );

    $stale = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', $created->plaintextPassword));
    expect($stale->purpose)->toBe('mfa');

    // Presenting it to the enroll endpoint (wrong purpose) is rejected as
    // an invalid challenge, not silently accepted.
    ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($stale->challengeToken));
})->throws(MfaChallengeInvalid::class);

it('rejects a token resolved to no operator', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    ($fixture->resolveOperatorFromBearerToken)('unknown-token');
})->throws(InvalidOperatorCredentials::class);
