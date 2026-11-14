<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\OperatorRole;
use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Contracts\SavePortalSettingsCommand;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * ADR-0125 — the console opens the customer's space and renders the link
 * to copy.
 *
 * The rules the screen must not break:
 *
 *  - the link is a **one-time secret** (ADR-0083 §4): it is rendered in
 *    the response that opens it, and it never reaches the session store
 *    (ADR-0081 S-07);
 *  - the **audit** keeps the act — the project, the customer — and never
 *    the value of a link;
 *  - the console is **without JavaScript** (ADR-0104): the link sits in a
 *    read-only field the operator selects and copies, and the screen
 *    says when it expires and that it is worth one use;
 *  - a customer the project does not know is refused, in French.
 */
final class CustomerPortalSpaceConsoleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_sheet_offers_the_gesture(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->signInToConsole();

        $this->get("/admin/projects/{$project}/customers/CLIENT-1")
            ->assertOk()
            ->assertSee('Ouvrir l\'espace du client', false);
    }

    #[Test]
    public function the_gesture_opens_the_space_and_renders_the_link_to_copy(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->signInToConsole();

        $response = $this->post("/admin/projects/{$project}/customers/CLIENT-1/portal-session");

        $response->assertOk();
        $response->assertSee('Lien à copier');
        $response->assertSee('/portal/enter/');

        // The window: the link is worth one use and says when it dies.
        $response->assertSee('à usage unique');

        // The console has no JavaScript (ADR-0104): the link is in a
        // read-only field — the operator selects it and copies it.
        $html = $this->html($response);
        self::assertMatchesRegularExpression(
            '/<input[^>]*id="field-portal_link"[^>]*value="([^"]+)"[^>]*readonly/',
            $html,
            'le lien doit être dans un champ en lecture seule',
        );
        self::assertStringNotContainsString('onclick', $html);

        // The session is really open, for that customer, and it says when
        // it dies — the module's own column, not a guess.
        $session = DB::table('portal_sessions')->first();
        self::assertNotNull($session);
        self::assertSame($project, $session->project_id);
        self::assertSame('CLIENT-1', $session->customer_ref);

        $expiresAt = $session->token_expires_at;
        self::assertIsString($expiresAt);
        $response->assertSee(\Illuminate\Support\Carbon::parse($expiresAt)->format('d/m/Y à H:i'));
    }

    #[Test]
    public function the_link_is_never_written_in_the_session_store(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->signInToConsole();

        $response = $this->post("/admin/projects/{$project}/customers/CLIENT-1/portal-session");
        $link = $this->linkIn($this->html($response));

        // The secret is worth one use: it never outlives the response
        // that carries it (ADR-0081 S-07).
        /** @var \Illuminate\Session\Store $store */
        $store = $this->app->make('session')->driver();
        $handler = $store->getHandler();
        $written = $handler->read($store->getId());
        self::assertIsString($written);
        self::assertStringNotContainsString($link, $written, 'le lien a été écrit dans le store de session');
        self::assertStringNotContainsString($link, (string) json_encode($store->all()), 'le lien est dans la session vivante');

        // Reloaded, the page shows the gesture again, not the link.
        $this->get("/admin/projects/{$project}/customers/CLIENT-1")
            ->assertOk()
            ->assertDontSee($link);
    }

    #[Test]
    public function the_gesture_is_audited_without_the_link(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->signInToConsole();

        $response = $this->post("/admin/projects/{$project}/customers/CLIENT-1/portal-session");
        $link = $this->linkIn($this->html($response));

        $entry = DB::table('audit_log')->where('action', 'portal.session.opened')->orderByDesc('id')->first();

        self::assertNotNull($entry, 'le geste doit être audité');
        self::assertSame($project, $entry->project_id);
        self::assertIsString($entry->actor);
        self::assertIsString($entry->resource);
        self::assertStringStartsWith('operator:', $entry->actor);
        self::assertStringContainsString('customer:', $entry->resource);
        self::assertStringContainsString('CLIENT-1', $this->text($entry->context));

        // The link is a secret: it never enters the journal.
        self::assertStringNotContainsString($link, $this->text($entry->context));
        self::assertStringNotContainsString($link, $this->text($entry->after));
        self::assertStringNotContainsString($link, $this->text($entry->before));
    }

    #[Test]
    public function a_customer_the_project_does_not_know_is_refused_in_french(): void
    {
        $project = $this->project();
        $this->signInToConsole();

        $this->post("/admin/projects/{$project}/customers/INCONNU/portal-session")
            ->assertSessionHasErrors(['resource' => 'Ressource introuvable.']);

        // Nothing was opened.
        self::assertSame(0, DB::table('portal_sessions')->count());
        self::assertSame(0, DB::table('audit_log')->where('action', 'portal.session.opened')->count());
    }

    #[Test]
    public function the_login_by_code_shows_the_address_and_the_sending_session_of_the_project(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->directLogin($project, 'ecole');
        $this->whatsappSession($project, 'ecole');
        $this->signInToConsole(OperatorRole::PlatformAdmin);

        $response = $this->post("/admin/projects/{$project}/customers/CLIENT-1/portal-session");

        $response->assertOk();
        $response->assertSee('/portal/'.$project.'/login');
        $response->assertSee('Session WhatsApp d\'envoi', false);
        $response->assertSee('ecole');
        $response->assertSee('c\'est la session d\'envoi de ce projet');
    }

    #[Test]
    public function a_global_sending_session_is_said_to_be_global(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->directLogin($project, 'partage');
        // A session of the platform: every project may send with it
        // (ADR-0057).
        $this->whatsappSession(null, 'partage');
        $this->signInToConsole(OperatorRole::PlatformAdmin);

        $response = $this->post("/admin/projects/{$project}/customers/CLIENT-1/portal-session");

        $response->assertOk();
        $response->assertSee('partage');
        $response->assertSee('c\'est une session globale');
        $response->assertSee('tous les projets peuvent s\'en servir');
    }

    #[Test]
    public function a_project_without_the_login_by_code_shows_nothing_of_it(): void
    {
        $project = $this->project();
        $this->customer($project, 'CLIENT-1');
        $this->signInToConsole();

        $this->post("/admin/projects/{$project}/customers/CLIENT-1/portal-session")
            ->assertOk()
            ->assertDontSee('Connexion par code');
    }

    // ------------------------------------------------------------------ helpers

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'test:console', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'test:console', correlationId: null)->project->id;
    }

    private function customer(string $projectId, string $ref): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: 'Client '.$ref, email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test:console', correlationId: null,
        ));
    }

    /**
     * The project offers the login by code, from that sending session.
     */
    private function directLogin(string $projectId, string $session): void
    {
        app(ManagePortalSettings::class)->save(new SavePortalSettingsCommand(
            projectId: $projectId,
            displayName: 'Mon espace',
            logoUrl: null,
            primaryColor: '#2563eb',
            supportUrl: null,
            locale: 'fr',
            allowedReturnUrls: [],
            allowSelfCancel: false,
            allowPlanChange: false,
            paymentChannels: [],
            actor: 'test:console',
            directLoginEnabled: true,
            directLoginSession: $session,
        ), AccessScope::forProject($projectId));
    }

    /**
     * A WhatsApp session — the project's own, or the platform's (null).
     */
    private function whatsappSession(?string $projectId, string $sessionRef): void
    {
        app(ChannelSessionRepository::class)->save(new ChannelSession(
            id: (string) Str::uuid(),
            projectId: $projectId,
            channel: 'whatsapp',
            sessionRef: $sessionRef,
            createdAt: new DateTimeImmutable,
        ));
    }

    /**
     * A column of the journal, as text.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The link the page renders.
     */
    private function linkIn(string $html): string
    {
        $found = [];
        self::assertSame(1, preg_match('/id="field-portal_link"[^>]*value="([^"]+)"/', $html, $found), 'le lien doit être dans le champ');

        return html_entity_decode($found[1] ?? '');
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }
}
