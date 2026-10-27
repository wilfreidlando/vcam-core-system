<?php

declare(strict_types=1);

use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\Dto\VerifyOperatorMfaCommand;
use Core\Identity\Application\UseCase\AcceptOperatorInvitation as AcceptOperatorInvitationUseCase;
use Core\Identity\Application\UseCase\ConfirmOperatorMfaEnrollment;
use Core\Identity\Application\UseCase\EnrollOperatorMfa;
use Core\Identity\Application\UseCase\InviteOperator as InviteOperatorUseCase;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Application\UseCase\ResolveAccessScopeUseCase;
use Core\Identity\Application\UseCase\ResolveOperatorFromBearerToken;
use Core\Identity\Application\UseCase\VerifyOperatorMfa;
use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
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
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorInvitationRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationMembershipRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;
use Core\Identity\Tests\Unit\Fakes\SequentialIdentifier;

final readonly class OperatorAuthFlowFixture
{
    /**
     * The password every operator of this fixture signs in with: the
     * invitee chooses it at activation (ADR-0081 S-07), so a test that
     * needs to know it must choose it itself.
     */
    public const string PASSWORD = 'a chosen passphrase';

    public function __construct(
        public InviteOperator $inviteOperator,
        public AcceptOperatorInvitation $acceptOperatorInvitation,
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

    /**
     * Invites an email address, then activates the invitation with the
     * chosen password — the real path an account takes since ADR-0081
     * S-07. Returns the account it created.
     */
    public function createOperator(string $email, OperatorRole $role): Operator
    {
        $invitation = $this->inviteOperator->invite(
            new InviteOperatorCommand($email, $role->value),
            actor: 'service:cli',
            correlationId: null,
        );

        $accepted = $this->acceptOperatorInvitation->accept(new AcceptOperatorInvitationCommand(
            token: (string) $invitation->token,
            name: 'Ops',
            password: self::PASSWORD,
        ));

        return $this->operators->find($accepted->operatorId)
            ?? throw new LogicException('The activated account was not created.');
    }

    public static function make(): self
    {
        $operators = new InMemoryOperatorRepository;
        $invitations = new InMemoryOperatorInvitationRepository;
        $challenges = new InMemoryLoginChallengeCodec;
        $totp = new FixedTotpProvider;
        $tokenIssuer = new FakeOperatorTokenIssuer;
        $tokenResolver = new FakeOperatorTokenResolver;
        $audit = new RecordingAuditLogger;
        $clock = new FixedClock;

        return new self(
            inviteOperator: new InviteOperatorUseCase($invitations, $operators, new SequentialIdentifier, $audit, $clock, new PassthroughUnitOfWork),
            acceptOperatorInvitation: new AcceptOperatorInvitationUseCase($invitations, $operators, $challenges, new SequentialIdentifier, $audit, $clock, new PassthroughUnitOfWork),
            loginOperator: new LoginOperator($operators, $challenges, $clock),
            enrollOperatorMfa: new EnrollOperatorMfa($operators, $challenges, $totp, $clock),
            confirmOperatorMfaEnrollment: new ConfirmOperatorMfaEnrollment($operators, $challenges, $totp, $tokenIssuer, $audit, $clock),
            verifyOperatorMfa: new VerifyOperatorMfa($operators, $challenges, $totp, $tokenIssuer, $audit, $clock),
            resolveOperatorFromBearerToken: new ResolveOperatorFromBearerToken(
                $tokenResolver,
                $operators,
                new ResolveAccessScopeUseCase($operators, new InMemoryOrganizationMembershipRepository, new InMemoryProjectRepository),
            ),
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

    $created = $fixture->createOperator('ops@example.com', OperatorRole::SupportOperator);

    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    expect($challenge->purpose)->toBe('mfa_enrollment');

    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));
    expect($enrollment->secret)->not->toBeEmpty()
        ->and($enrollment->otpauthUri)->toContain('ops@example.com');

    $session = ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );
    expect($session->operatorId)->toBe($created->id)
        ->and($session->accessToken)->not->toBeEmpty();

    $operator = $fixture->operators->find($created->id);
    assert($operator instanceof Operator);
    expect($operator->mfaEnabled())->toBeTrue();

    // Wire the issued token into the resolver fake, then confirm it resolves
    // back to the same operator — this is what ResolveOperatorMiddleware
    // does on every subsequent admin-console request.
    $fixture->tokenResolver->register($session->accessToken, $created->id);
    $context = ($fixture->resolveOperatorFromBearerToken)($session->accessToken);

    expect($context->operatorId)->toBe($created->id)
        ->and($context->role)->toBe(OperatorRole::SupportOperator);

    $actions = array_map(static fn ($entry) => $entry->action, $fixture->audit->entries);
    expect($actions)->toContain('operator.invited')
        ->and($actions)->toContain('operator.invitation_accepted')
        ->and($actions)->toContain('operator.mfa_enrolled');
});

it('returning login skips straight to mfa verification once already enrolled', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    $created = $fixture->createOperator('ops@example.com', OperatorRole::FinanceOperator);

    $firstChallenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($firstChallenge->challengeToken));
    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );

    $secondChallenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    expect($secondChallenge->purpose)->toBe('mfa');

    $session = ($fixture->verifyOperatorMfa)(
        new VerifyOperatorMfaCommand($secondChallenge->challengeToken, FixedTotpProvider::VALID_CODE),
    );
    expect($session->operatorId)->toBe($created->id);
});

it('rejects a wrong password without revealing whether the email exists', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $fixture->createOperator('ops@example.com', OperatorRole::SupportOperator);

    ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', 'wrong-password'));
})->throws(InvalidOperatorCredentials::class);

it('rejects an unknown email the same way as a wrong password', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    ($fixture->loginOperator)(new LoginOperatorCommand('nobody@example.com', 'whatever'));
})->throws(InvalidOperatorCredentials::class);

it('rejects an invalid mfa code during confirmation', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $created = $fixture->createOperator('ops@example.com', OperatorRole::SupportOperator);
    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));

    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, 'wrong-code'),
    );
})->throws(InvalidMfaCode::class);

it('rejects enrolling mfa twice', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $created = $fixture->createOperator('ops@example.com', OperatorRole::SupportOperator);
    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));
    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );

    // A fresh enrollment-purpose challenge issued for an operator whose
    // MFA is already confirmed — as if the first-login step were replayed.
    $replayedChallenge = $fixture->challenges->issue($created->id, 'mfa_enrollment', $fixture->clock->now());
    ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($replayedChallenge));
})->throws(MfaAlreadyEnrolled::class);

it('rejects a stale mfa_enrollment challenge presented after mfa is already enabled', function (): void {
    $fixture = OperatorAuthFlowFixture::make();
    $created = $fixture->createOperator('ops@example.com', OperatorRole::SupportOperator);
    $challenge = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    $enrollment = ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challenge->challengeToken));
    ($fixture->confirmOperatorMfaEnrollment)(
        new ConfirmOperatorMfaEnrollmentCommand($enrollment->confirmChallengeToken, FixedTotpProvider::VALID_CODE),
    );

    $stale = ($fixture->loginOperator)(new LoginOperatorCommand('ops@example.com', OperatorAuthFlowFixture::PASSWORD));
    expect($stale->purpose)->toBe('mfa');

    // Presenting it to the enroll endpoint (wrong purpose) is rejected as
    // an invalid challenge, not silently accepted.
    ($fixture->enrollOperatorMfa)(new EnrollOperatorMfaCommand($stale->challengeToken));
})->throws(MfaChallengeInvalid::class);

it('rejects a token resolved to no operator', function (): void {
    $fixture = OperatorAuthFlowFixture::make();

    ($fixture->resolveOperatorFromBearerToken)('unknown-token');
})->throws(InvalidOperatorCredentials::class);
