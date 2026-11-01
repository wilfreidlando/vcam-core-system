<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * ADR-0044: the operator plane's login flow (password → MFA enrollment →
 * confirm → bearer token → authenticated request), exercised against real
 * HTTP routes and the real Sanctum-backed token resolution — not direct
 * use-case calls.
 */
final class OperatorAuthHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The password every operator of this test signs in with: the invitee
     * chooses it at activation (ADR-0081 S-07), so a test that needs to
     * know it must choose it itself.
     */
    private const string PASSWORD = 'a chosen passphrase';

    #[Test]
    public function a_fresh_operator_enrolls_mfa_on_first_login_and_authenticates(): void
    {
        $created = $this->createOperator('ops@example.com', OperatorRole::SupportOperator);

        $login = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops@example.com',
            'password' => self::PASSWORD,
        ])->assertOk());

        self::assertSame('mfa_enrollment', $login['purpose']);

        $enroll = $this->jsonBody($this->postJson('/v1/operators/mfa/enroll', [
            'challenge_token' => $login['challenge_token'],
        ])->assertOk());

        self::assertArrayHasKey('secret', $enroll);
        self::assertArrayHasKey('otpauth_uri', $enroll);

        $code = (new Google2FA)->getCurrentOtp($this->stringField($enroll, 'secret'));

        $confirm = $this->jsonBody($this->postJson('/v1/operators/mfa/confirm', [
            'challenge_token' => $enroll['confirm_challenge_token'],
            'code' => $code,
        ])->assertCreated());

        self::assertSame($created, $confirm['operator_id']);
        $accessToken = $this->stringField($confirm, 'access_token');

        $this->getJson('/v1/operators/me', ['Authorization' => "Bearer {$accessToken}"])
            ->assertOk()
            ->assertJson([
                'operator_id' => $created,
                'email' => 'ops@example.com',
                'role' => 'support_operator',
            ]);
    }

    #[Test]
    public function a_returning_operator_skips_straight_to_mfa_verification(): void
    {
        $created = $this->createOperator('ops2@example.com', OperatorRole::FinanceOperator);

        $firstLogin = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops2@example.com',
            'password' => self::PASSWORD,
        ])->assertOk());
        $enroll = $this->jsonBody($this->postJson('/v1/operators/mfa/enroll', [
            'challenge_token' => $firstLogin['challenge_token'],
        ])->assertOk());
        $secret = $this->stringField($enroll, 'secret');
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/v1/operators/mfa/confirm', [
            'challenge_token' => $enroll['confirm_challenge_token'],
            'code' => $code,
        ])->assertCreated();

        $secondLogin = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops2@example.com',
            'password' => self::PASSWORD,
        ])->assertOk());

        self::assertSame('mfa', $secondLogin['purpose']);

        // A genuinely later code, one time step after the one `confirm`
        // just consumed — not the same window's `getCurrentOtp()`, which
        // the anti-replay fix (review finding, Lot 6 follow-up) now
        // correctly rejects as a replay of the code just verified above.
        $google2fa = new Google2FA;
        $verifyCode = $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
        $verify = $this->jsonBody($this->postJson('/v1/operators/mfa/verify', [
            'challenge_token' => $secondLogin['challenge_token'],
            'code' => $verifyCode,
        ])->assertCreated());

        self::assertSame($created, $verify['operator_id']);
    }

    #[Test]
    public function logout_revokes_the_token(): void
    {
        $created = $this->createOperator('ops3@example.com', OperatorRole::ReadOnlyAuditor);
        $login = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops3@example.com',
            'password' => self::PASSWORD,
        ])->assertOk());
        $enroll = $this->jsonBody($this->postJson('/v1/operators/mfa/enroll', [
            'challenge_token' => $login['challenge_token'],
        ])->assertOk());
        $code = (new Google2FA)->getCurrentOtp($this->stringField($enroll, 'secret'));
        $confirm = $this->jsonBody($this->postJson('/v1/operators/mfa/confirm', [
            'challenge_token' => $enroll['confirm_challenge_token'],
            'code' => $code,
        ])->assertCreated());
        $accessToken = $this->stringField($confirm, 'access_token');

        $this->postJson('/v1/operators/logout', [], ['Authorization' => "Bearer {$accessToken}"])
            ->assertNoContent();

        $this->getJson('/v1/operators/me', ['Authorization' => "Bearer {$accessToken}"])
            ->assertStatus(401);
    }

    #[Test]
    public function wrong_credentials_and_missing_auth_are_rejected_as_rfc7807(): void
    {
        $this->postJson('/v1/operators/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->getJson('/v1/operators/me')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    /**
     * Invites an email address, then activates the invitation with the
     * chosen password — the real path an account takes since ADR-0081
     * S-07.
     */
    private function createOperator(string $email, OperatorRole $role): string
    {
        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand($email, $role->value),
            actor: 'service:test',
            correlationId: null,
        );

        $accepted = app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: (string) $invitation->token,
            name: 'Ops',
            password: self::PASSWORD,
        ));

        return $accepted->operatorId;
    }

    /**
     * @param TestResponse<\Illuminate\Http\JsonResponse> $response
     * @return array<string, mixed>
     */
    private function jsonBody(TestResponse $response): array
    {
        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
