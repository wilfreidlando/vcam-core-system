<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * Account management as an operator and an invitee live it in a browser (found by the browser
 * session: no page accepted an invitation, and an administrator could revoke their own account).
 */
final class AccountManagementConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_invitee_activates_their_account_on_the_web_page_and_is_handed_to_the_second_factor(): void
    {
        $token = (string) app(InviteOperator::class)->invite(new InviteOperatorCommand('finance@example.com', OperatorRole::FinanceOperator->value), actor: 'service:test', correlationId: null)->token;

        $this->get('/admin/invitation')->assertOk()->assertSee('Activer mon compte');

        // The refusals say what to do.
        $this->post('/admin/invitation', ['token' => str_repeat('x', 43), 'password' => 'Un mot de passe solide', 'password_confirmation' => 'Un mot de passe solide'])
            ->assertSessionHasErrors(['token' => 'Aucune invitation ne correspond à ce jeton. Vérifiez que vous l\'avez copié en entier.']);
        $this->post('/admin/invitation', ['token' => $token, 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertSessionHasErrors('token');
        $this->post('/admin/invitation', ['token' => $token, 'password' => 'Un mot de passe solide', 'password_confirmation' => 'autre chose'])
            ->assertSessionHasErrors('password');

        // The right token and a chosen password: the account exists and MFA enrolment comes next.
        $this->post('/admin/invitation', ['token' => $token, 'name' => 'Fabrice', 'password' => 'Un mot de passe solide', 'password_confirmation' => 'Un mot de passe solide'])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        $enrol = $this->get('/admin/mfa/enroll')->assertOk();
        // The QR is an image, not a page-wide string of text (found by the browser session).
        $enrol->assertSee('<svg', false)->assertDontSee('data:image/svg+xml;base64', false);

        // The same token cannot be used twice.
        $this->post('/admin/invitation', ['token' => $token, 'password' => 'Un mot de passe solide', 'password_confirmation' => 'Un mot de passe solide'])
            ->assertSessionHasErrors('token');
    }

    #[Test]
    public function an_account_cannot_revoke_itself_nor_the_last_platform_administrator(): void
    {
        $adminId = $this->loginAsPlatformAdmin();

        $this->post("/admin/operators/{$adminId}/revoke")->assertSessionHasErrors('operatorId');
        self::assertNull(DB::table('operators')->where('id', $adminId)->value('revoked_at'), 'The administrator is still active.');

        // A second administrator can be revoked by the first; the first stays the last one.
        $second = OperatorFactory::create('second@example.com', OperatorRole::PlatformAdmin);
        $this->post("/admin/operators/{$second}/revoke")->assertRedirect(route('admin.operators.index'));
        self::assertNotNull(DB::table('operators')->where('id', $second)->value('revoked_at'));

        $other = OperatorFactory::create('finance@example.com', OperatorRole::FinanceOperator);
        $this->post("/admin/operators/{$other}/revoke")->assertRedirect(route('admin.operators.index'));
    }

    private function loginAsPlatformAdmin(): string
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', OperatorRole::PlatformAdmin);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $this->get('/admin/mfa/enroll')->getContent();
        $html = is_string($content) ? $content : '';
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));

        return $created;
    }
}
