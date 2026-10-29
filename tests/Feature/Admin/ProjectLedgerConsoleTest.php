<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * The ledger of a project in the console: read-only, balanced, by currency.
 * Found missing by the browser session — the read ports existed, no screen used them.
 */
final class ProjectLedgerConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_ledger_page_shows_the_balanced_trial_balance_and_the_transactions(): void
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $projectId = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project->id;
        $this->loginAndEnrollMfa();

        $this->get("/admin/projects/{$projectId}/ledger")->assertOk()->assertSee('Aucune écriture pour ce projet.');

        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'clearing', 'my-coolpay', 'XAF'));
        $collections = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'collections', '', 'XAF'));
        app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            projectId: $projectId, type: 'payment_collection', externalRef: 'pay-1', idempotencyKey: 'k-1',
            entries: [
                new LedgerEntryInput($clearing->id, Direction::Debit, 50_000, 'XAF'),
                new LedgerEntryInput($collections->id, Direction::Credit, 50_000, 'XAF'),
            ],
        ));

        $page = $this->get("/admin/projects/{$projectId}/ledger")->assertOk();
        $page->assertSee('Équilibrée')->assertSee('50 000')->assertSee('payment_collection')->assertSee('clearing');
        $this->get("/admin/projects/{$projectId}/ledger?type=other_type")->assertOk()->assertSee('Aucune transaction.');
    }

    private function loginAndEnrollMfa(): void
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
    }
}
