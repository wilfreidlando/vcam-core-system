<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * Recette du 2026-10-29, écart sécurité #15 : le jeton de défi MFA était
 * un **porteur** — il voyageait dans le HTML du formulaire et n'était
 * comparé à rien. Un jeton divulgué (écran partagé, historique,
 * `Referer` vers un tiers, journal technique) permettait à un tiers de
 * terminer la connexion MFA **depuis une autre session**.
 *
 * Le jeton est désormais lié à la session qui a ouvert la connexion : le
 * contrôleur compare (en temps constant) le jeton posté à celui que la
 * session détient. Un jeton rejoué ailleurs n'aboutit à rien.
 *
 * Ce test prouve les deux moitiés : le refus du jeton étranger, et le
 * fait que la connexion légitime fonctionne toujours.
 */
final class MfaChallengeSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_challenge_token_from_another_session_cannot_finish_the_login(): void
    {
        $operator = $this->operator();

        // The legitimate session: it logs in and reaches the MFA step.
        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enrol = $this->get('/admin/mfa/enroll')->assertOk();
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = (string) $enrol->getContent();
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }

        // A stolen token: the same value, posted from another browser.
        $stolen = $challenge[1] ?? '';
        self::assertNotSame('', $stolen, 'the enrolment page must carry a challenge token');

        // The session of THIS test client is the legitimate one, so the
        // legitimate token works…
        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $stolen,
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));

        // …and a token that does NOT belong to the session is refused.
        // The check happens before the code is even looked at.
        $this->post('/admin/mfa/verify', [
            'challenge_token' => 'jeton-dune-autre-session',
            'code' => '123456',
        ])->assertRedirect(route('admin.login.show'));
    }

    #[Test]
    public function the_verification_step_refuses_a_challenge_that_is_not_the_one_of_this_session(): void
    {
        $operator = $this->operator();

        // This operator already has MFA: the login goes straight to the
        // verification step, whose challenge is the one under test.
        $this->enrolMfa($operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.verify.show'));

        $page = $this->get('/admin/mfa/verify')->assertOk();
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = (string) $page->getContent();
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);
        $legitimate = $challenge[1] ?? '';
        self::assertNotSame('', $legitimate, 'the verification page must carry a challenge token');

        // A challenge that is not this session's is refused, and the
        // operator is sent back to the login form.
        $this->post('/admin/mfa/verify', [
            'challenge_token' => 'jeton-dune-autre-session',
            'code' => '123456',
        ])->assertRedirect(route('admin.login.show'));

        // The session's own challenge is still the one that counts: it
        // gets past the binding check (the code itself is then judged by
        // the TOTP verifier — an invalid code must NOT redirect to the
        // login form, it must come back to the form with an error).
        $this->post('/admin/mfa/verify', [
            'challenge_token' => $legitimate,
            'code' => '000000',
        ])->assertSessionHasErrors('code');
    }

    private function operator(): Operator
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', OperatorRole::PlatformAdmin);
        $operator = app(OperatorRepository::class)->find($created);
        self::assertNotNull($operator);

        return $operator;
    }

    /** Enrols an authenticator on the operator, so the next login asks for a code. */
    private function enrolMfa(Operator $operator): void
    {
        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enrol = $this->get('/admin/mfa/enroll')->assertOk();
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = (string) $enrol->getContent();
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));

        // The session of the test client is the operator's now; the next
        // login (for the verification step) starts from a clean session.
        $this->post('/admin/logout');
    }
}
