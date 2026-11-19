<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0128 — le MFA de l'opérateur se désarme par **une** variable
 * d'environnement : `IDENTITY_DISABLE_MFA=true`, partout, sans condition.
 *
 * Ce que le drapeau laisse en place, c'est la **trace** : chaque connexion
 * sans second facteur est journalisée (`operator.mfa_skipped`), sur les deux
 * plans — la console web et l'API. Une fenêtre sans MFA est donc visible
 * dans le journal, même en production.
 */
final class MfaDisabledLoginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_console_login_gives_the_session_right_away_and_leaves_a_trace(): void
    {
        config(['identity.disable_mfa' => true]);
        $email = uniqid('op-', true).'@example.com';
        OperatorFactory::create($email, OperatorRole::PlatformAdmin);

        // No MFA screen: the password alone opens the session.
        $this->post('/admin/login', ['email' => $email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));

        $entry = DB::table('audit_log')->where('action', 'operator.mfa_skipped')->orderByDesc('id')->first();

        self::assertNotNull($entry, 'une connexion sans MFA doit laisser une trace');
        $after = json_decode($this->text($entry->after), true);
        self::assertIsArray($after);
        self::assertSame('IDENTITY_DISABLE_MFA', $after['reason'] ?? null);
        self::assertSame('web', $after['plane'] ?? null);
    }

    #[Test]
    public function the_api_login_gives_the_token_right_away_and_leaves_a_trace(): void
    {
        config(['identity.disable_mfa' => true]);
        $email = uniqid('op-', true).'@example.com';
        OperatorFactory::create($email, OperatorRole::PlatformAdmin);

        $this->postJson('/v1/operators/login', ['email' => $email, 'password' => OperatorFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('mfa', false);

        $entry = DB::table('audit_log')->where('action', 'operator.mfa_skipped')->orderByDesc('id')->first();

        self::assertNotNull($entry, 'une connexion sans MFA doit laisser une trace');
        $after = json_decode($this->text($entry->after), true);
        self::assertIsArray($after);
        self::assertSame('IDENTITY_DISABLE_MFA', $after['reason'] ?? null);
        self::assertSame('api', $after['plane'] ?? null);
    }

    #[Test]
    public function the_mfa_stays_on_when_the_variable_is_not_set(): void
    {
        config(['identity.disable_mfa' => false]);
        $email = uniqid('op-', true).'@example.com';
        OperatorFactory::create($email, OperatorRole::PlatformAdmin);

        // The password alone is not enough: the operator is sent to the MFA
        // screen, and nothing is recorded as skipped.
        $this->post('/admin/login', ['email' => $email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        self::assertSame(0, DB::table('audit_log')->where('action', 'operator.mfa_skipped')->count());
    }

    /**
     * A column of the journal, as text.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
