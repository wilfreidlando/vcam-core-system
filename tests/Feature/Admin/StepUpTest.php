<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Identity\Presentation\Http\Middleware\StepUpGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Écarts #5 et #17 de la recette 2026-10-29 (annexe sécurité) : le MFA n'est qu'une porte
 * d'entrée ; une action qui **déplace de l'argent** exige un code TOTP saisi dans les 5
 * dernières minutes (`operator.step_up`), et la console plafonne ses écritures sensibles
 * (`console-sensitive`).
 *
 * ADR-0105 : l'élévation est réservée à l'argent. Les autres écritures sensibles — endpoints
 * webhook, clés d'API, opérateurs, membres d'organisation, effacement d'un client — ne
 * redemandent plus de code : elles restent auditées et plafonnées.
 *
 * Les tests posent eux-mêmes l'état d'élévation (`unelevated()`), pour ne pas dépendre
 * de l'appel `markElevated()` du login (`AuthController::completeLogin()`).
 */
final class StepUpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    /**
     * A write that moves money. The identifier does not need to exist: the
     * step-up is checked before the controller.
     */
    private const MONEY_WRITE = '/admin/money-exceptions/01a0a1cc-0000-7000-8000-000000000401/resolve';

    /** A write that does not move money — ADR-0105: no TOTP is asked again. */
    private const PLAIN_WRITE = '/admin/operators';

    #[Test]
    public function a_write_that_moves_money_without_a_recent_proof_redirects_to_the_step_up_screen_and_keeps_the_destination(): void
    {
        $this->signInToConsole();
        $this->unelevated();

        $this->from('/admin/money-exceptions')
            ->post(self::MONEY_WRITE, ['resolution' => 'no_action', 'note' => 'Rien à faire.'])
            ->assertRedirect(route('admin.step-up.show'))
            ->assertSessionHas(StepUpGate::RETURN_KEY, '/admin/money-exceptions');
    }

    #[Test]
    public function a_write_that_does_not_move_money_is_not_blocked_by_the_step_up(): void
    {
        // ADR-0105 — l'élévation est réservée à l'argent. Les autres
        // écritures sensibles (ici : inviter un opérateur) ne redemandent
        // pas de code TOTP ; elles restent auditées et plafonnées.
        $this->signInToConsole();
        $this->unelevated();

        $location = $this->post(self::PLAIN_WRITE, ['email' => 'x@example.com', 'role' => 'platform_admin'])
            ->headers->get('Location');

        self::assertNotSame(route('admin.step-up.show'), $location, 'une écriture qui ne bouge pas d\'argent ne demande pas de TOTP');
    }

    #[Test]
    public function the_step_up_screen_is_accessible_and_labelled(): void
    {
        $this->signInToConsole();
        $this->unelevated();

        $response = $this->get('/admin/step-up')->assertOk();
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = (string) $response->getContent();
        self::assertStringContainsString('<h1', $html);
        self::assertStringContainsString('autocomplete="one-time-code"', $html);
        self::assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
    }

    #[Test]
    public function a_right_code_grants_the_elevation_returns_to_the_origin_and_rotates_the_session_id(): void
    {
        $operatorId = $this->signedInOperatorId();
        $this->unelevated();

        $this->from('/admin/money-exceptions')->post(self::MONEY_WRITE, ['resolution' => 'no_action', 'note' => 'Rien à faire.']);
        $before = $this->sessionId();

        $this->post('/admin/step-up', ['code' => $this->freshCode($operatorId)])
            ->assertRedirect('/admin/money-exceptions')
            ->assertSessionHasNoErrors();

        self::assertNotSame($before, $this->sessionId(), 'l\'élévation fait tourner l\'identifiant de session (ADR-0081)');

        $location = $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])->headers->get('Location');
        self::assertNotSame(route('admin.step-up.show'), $location, 'l\'action n\'est plus redirigée vers l\'élévation');
    }

    #[Test]
    public function a_wrong_code_is_refused_with_a_message_and_grants_nothing(): void
    {
        $this->signInToConsole();
        $this->unelevated();

        $this->from('/admin/step-up')
            ->post('/admin/step-up', ['code' => '000000'])
            ->assertRedirect('/admin/step-up')
            ->assertSessionHasErrors('code');

        $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])
            ->assertRedirect(route('admin.step-up.show'));
    }

    #[Test]
    public function a_replayed_code_is_refused(): void
    {
        $operatorId = $this->signedInOperatorId();
        $this->unelevated();
        $code = $this->freshCode($operatorId);

        $this->post('/admin/step-up', ['code' => $code])->assertSessionHasNoErrors();

        // La preuve expire (ou la session est reprise) : le même code ne la renouvelle pas.
        $this->unelevated();
        $this->from('/admin/step-up')
            ->post('/admin/step-up', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])
            ->assertRedirect(route('admin.step-up.show'));
    }

    #[Test]
    public function the_elevation_expires_after_five_minutes(): void
    {
        $operatorId = $this->signedInOperatorId();
        $this->unelevated();
        $this->post('/admin/step-up', ['code' => $this->freshCode($operatorId)])->assertSessionHasNoErrors();

        $this->travel(4)->minutes();
        $stillValid = $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])->headers->get('Location');
        self::assertNotSame(route('admin.step-up.show'), $stillValid, 'à 4 minutes l\'élévation tient encore');

        $this->travel(2)->minutes();
        $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])
            ->assertRedirect(route('admin.step-up.show'));
    }

    #[Test]
    public function a_read_is_never_blocked_by_the_step_up(): void
    {
        $this->signInToConsole();
        $this->unelevated();

        $this->get('/admin/operators')->assertOk();
        $this->get('/admin/operators/create')->assertOk();
    }

    #[Test]
    public function a_json_caller_gets_a_problem_instead_of_a_redirect(): void
    {
        $this->signInToConsole();
        $this->unelevated();

        $this->postJson(self::MONEY_WRITE, ['email' => 'x@example.com'])
            ->assertStatus(403)
            ->assertJsonPath('type', 'https://errors.core-system.internal/step-up-required');
    }

    #[Test]
    public function with_mfa_disabled_the_step_up_is_skipped(): void
    {
        config(['identity.disable_mfa' => true]);
        $email = uniqid('op-', true).'@example.com';
        OperatorFactory::create($email, OperatorRole::PlatformAdmin);

        $this->post('/admin/login', ['email' => $email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->unelevated();

        $location = $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])->headers->get('Location');
        self::assertNotSame(route('admin.step-up.show'), $location);

        $this->get('/admin/step-up')->assertRedirect();
    }

    #[Test]
    public function the_step_up_screen_requires_a_signed_in_operator(): void
    {
        $this->get('/admin/step-up')->assertRedirect(route('admin.login.show'));
        $this->post('/admin/step-up', ['code' => '123456'])->assertRedirect(route('admin.login.show'));
    }

    #[Test]
    public function the_sensitive_writes_of_the_console_are_capped_per_operator(): void
    {
        $operatorId = $this->signedInOperatorId();
        $this->unelevated();
        $this->post('/admin/step-up', ['code' => $this->freshCode($operatorId)])->assertSessionHasNoErrors();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])->assertStatus(302);
        }

        $this->post(self::MONEY_WRITE, ['email' => 'x@example.com'])->assertStatus(429);
    }

    #[Test]
    public function the_code_attempts_of_the_step_up_are_capped(): void
    {
        $this->signInToConsole();
        $this->unelevated();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/admin/step-up', ['code' => '00000'.$attempt])->assertStatus(302);
        }

        $this->post('/admin/step-up', ['code' => '000009'])->assertStatus(429);
    }

    private function sessionId(): string
    {
        return app('session.store')->getId();
    }

    /** L'opérateur qui vient de se connecter à la console. */
    private function signedInOperatorId(): string
    {
        $email = $this->signInToConsole();
        $operator = app(OperatorRepository::class)->findByEmail($email);
        self::assertInstanceOf(Operator::class, $operator);

        return $operator->id;
    }

    /** Efface la preuve d'élévation, comme si les cinq minutes étaient passées. */
    private function unelevated(): void
    {
        $this->withSession([StepUpGate::SESSION_KEY => null]);
    }

    /**
     * Un code TOTP du pas de temps SUIVANT : valable (fenêtre de ±1 pas) et plus récent
     * que celui consommé par le login — l'anti-rejeu refuse le pas courant.
     */
    private function freshCode(string $operatorId): string
    {
        $operator = app(OperatorRepository::class)->find($operatorId);
        self::assertInstanceOf(Operator::class, $operator);
        $secret = $operator->mfaSecret();
        self::assertIsString($secret);

        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
    }
}
