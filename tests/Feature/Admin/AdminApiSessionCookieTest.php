<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Écart #7 de la recette 2026-10-29 (annexe sécurité) : le plan `/v1/admin/*` n'a pas de
 * jeton CSRF, mais `operator.auth` retombait sur le cookie de session — une page tierce
 * pouvait donc déclencher `POST /v1/admin/payment-reconciliation-runs` au nom d'un opérateur
 * connecté. Le cookie ne sert plus qu'à lire ; une écriture exige l'en-tête Bearer.
 */
final class AdminApiSessionCookieTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function a_write_carried_by_the_session_cookie_alone_is_refused(): void
    {
        $this->signInToConsole();

        $this->postJson('/v1/admin/payment-reconciliation-runs')
            ->assertStatus(401)
            ->assertJsonPath('type', 'https://errors.core-system.internal/unauthenticated');

        $this->post('/v1/admin/payment-reconciliation-runs')->assertStatus(401);
    }

    #[Test]
    public function a_refused_cookie_write_does_not_sign_the_operator_out(): void
    {
        $this->signInToConsole();

        $this->postJson('/v1/admin/payment-reconciliation-runs')->assertStatus(401);

        // La session survit : ce n'est pas une déconnexion, la console répond encore.
        $this->get('/admin')->assertOk();
    }

    #[Test]
    public function the_same_write_with_a_bearer_token_is_accepted(): void
    {
        $this->signInToConsole();
        $token = session(ResolveOperatorMiddleware::SESSION_KEY);
        self::assertIsString($token);

        $this->postJson('/v1/admin/payment-reconciliation-runs', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJson(['checked' => 0, 'updated' => 0, 'errors' => 0]);
    }

    #[Test]
    public function the_session_cookie_still_reads_the_json_plane(): void
    {
        $this->signInToConsole();

        $this->getJson('/v1/admin/audit-log')->assertOk();
    }

    #[Test]
    public function the_session_cookie_is_lax(): void
    {
        self::assertSame('lax', config('session.same_site'), 'SESSION_SAME_SITE doit rester « lax » (écart #7).');
    }
}
