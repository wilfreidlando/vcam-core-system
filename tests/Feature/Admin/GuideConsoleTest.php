<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0113 — the operator guide, rendered in the console.
 *
 * The guide is a document, not a tenant resource: every operator reads the
 * same flows. The pages therefore need no scope, and the IDOR manifest does
 * not have to know them — there is no organisation in a guide flow.
 *
 * What this test proves: the pages answer, the flows are listed, a family that
 * does not exist is a 404, and the guide is behind the operator session like
 * every other console page.
 */
final class GuideConsoleTest extends TestCase
{
    #[Test]
    public function the_index_lists_the_flows_by_situation(): void
    {
        $this->login();

        $response = $this->get('/admin/guide');

        $response->assertOk();
        $response->assertSee('Guide de l’opérateur');
        $response->assertSee('Se connecter et confirmer mon identité');
        $response->assertSee('F01');
        // The five families are the guide's reading order.
        $response->assertSee('Se connecter et gérer les accès');
        $response->assertSee('Extraire et piloter');
    }

    #[Test]
    public function a_family_page_renders_its_flows(): void
    {
        $this->login();

        $response = $this->get('/admin/guide/acces');

        $response->assertOk();
        $response->assertSee('Se connecter et confirmer mon identité');
        $response->assertSee('id="f01"', false);
        // The rendered Markdown: a heading and a table come out as HTML.
        $response->assertSee('Ce qu’il faut savoir avant');
        $response->assertSee('<table>', false);
    }

    #[Test]
    public function a_family_that_does_not_exist_is_not_found(): void
    {
        $this->login();

        $this->get('/admin/guide/inconnu')->assertNotFound();
    }

    #[Test]
    public function the_guide_is_behind_the_operator_session(): void
    {
        $this->get('/admin/guide')->assertRedirect(route('admin.login.show'));
        $this->get('/admin/guide/acces')->assertRedirect(route('admin.login.show'));
    }

    /**
     * The console asks for a second proof on the first connection — the
     * test has to enrol the authenticator before it can read a page.
     */
    private function login(): void
    {
        $created = OperatorFactory::create(uniqid('guide-', true).'@example.com', OperatorRole::PlatformAdmin);
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
    }
}
