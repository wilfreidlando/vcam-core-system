<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Application\Dto\CreateOperatorCommand;
use Core\Identity\Application\UseCase\CreateOperator;
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

    #[Test]
    public function a_fresh_operator_enrolls_mfa_on_first_login_and_authenticates(): void
    {
        $created = app(CreateOperator::class)(
            new CreateOperatorCommand('ops@example.com', 'Ops', OperatorRole::SupportOperator),
            actor: 'service:test',
            correlationId: null,
        );

        $login = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops@example.com',
            'password' => $created->plaintextPassword,
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

        self::assertSame($created->operatorId, $confirm['operator_id']);
        $accessToken = $this->stringField($confirm, 'access_token');

        $this->getJson('/v1/operators/me', ['Authorization' => "Bearer {$accessToken}"])
            ->assertOk()
            ->assertJson([
                'operator_id' => $created->operatorId,
                'email' => 'ops@example.com',
                'role' => 'support_operator',
            ]);
    }

    #[Test]
    public function a_returning_operator_skips_straight_to_mfa_verification(): void
    {
        $created = app(CreateOperator::class)(
            new CreateOperatorCommand('ops2@example.com', 'Ops Two', OperatorRole::FinanceOperator),
            actor: 'service:test',
            correlationId: null,
        );

        $firstLogin = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops2@example.com',
            'password' => $created->plaintextPassword,
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
            'password' => $created->plaintextPassword,
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

        self::assertSame($created->operatorId, $verify['operator_id']);
    }

    #[Test]
    public function logout_revokes_the_token(): void
    {
        $created = app(CreateOperator::class)(
            new CreateOperatorCommand('ops3@example.com', 'Ops Three', OperatorRole::ReadOnlyAuditor),
            actor: 'service:test',
            correlationId: null,
        );
        $login = $this->jsonBody($this->postJson('/v1/operators/login', [
            'email' => 'ops3@example.com',
            'password' => $created->plaintextPassword,
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
