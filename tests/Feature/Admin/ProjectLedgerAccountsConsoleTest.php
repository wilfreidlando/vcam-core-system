<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\OperatorRole;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Payment\Contracts\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Support\Access\AccessScope;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Contracts\WalletBalanceView;
use Core\Wallet\Contracts\WalletCreditOrigin;
use Core\Wallet\Contracts\WalletCreditSource;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\OperatorFactory;
use Tests\TestCase;

/**
 * ADR-0125 §6 — the accounts of a project are visible, and the operator
 * tells at a glance the money of the project from the money of a client.
 *
 * The page is also the one that opens the movements of an account
 * (ADR-0125 §7): `AccountStatement` was implemented and bound, never
 * called.
 */
final class ProjectLedgerAccountsConsoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_accounts_of_the_project_are_named_and_the_money_of_a_client_is_told_apart(): void
    {
        [, $projectId] = $this->project();
        $this->signIn();

        $wallet = $this->wallet($projectId, 'PARTY-9', 'mycoolpay');
        $customer = $this->customer($projectId, 'AE0004');

        $accounts = app(AccountResolver::class);
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'customer_receivable', $customer->id, 'XAF'));
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'revenue', '', 'XAF'));

        $page = $this->get("/admin/projects/{$projectId}/ledger/accounts")->assertOk();

        // Every nature of the project has a name in French.
        $page->assertSee('Portefeuille client')
            ->assertSee('Encaissements — prestataire')
            ->assertSee('Créances clients')
            ->assertSee('Recettes');

        // The money of a client is told apart from the money of the project.
        $page->assertSee("l'argent du client")
            ->assertSee("l'argent dû par le client")
            ->assertSee("l'argent du projet");

        // The references are names, not identifiers.
        $page->assertSee('PARTY-9')->assertSee('AE0004')->assertSee('mycoolpay');

        // An identifier never reaches the screen.
        $visible = $this->visibleText($page);
        self::assertStringNotContainsString($wallet->walletId, $visible);
        self::assertStringNotContainsString($customer->id, $visible);
    }

    #[Test]
    public function an_account_that_never_moved_is_listed_all_the_same(): void
    {
        [, $projectId] = $this->project();
        $this->signIn();

        $accounts = app(AccountResolver::class);
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'withdrawal', '', 'XAF'));

        $this->get("/admin/projects/{$projectId}/ledger/accounts")
            ->assertOk()
            ->assertSee('Sorties de trésorerie');
    }

    #[Test]
    public function the_provider_is_named_when_the_operator_gave_it_a_name(): void
    {
        [, $projectId] = $this->project();
        $this->signIn();

        app(RegisterProviderApplication::class)->register(new RegisterProviderApplicationCommand(
            projectId: $projectId,
            providerCode: 'mycoolpay',
            publicKey: 'a public key',
            privateKey: 'a private key',
            label: 'MyCoolPay',
        ), AccessScope::forProject($projectId));

        $accounts = app(AccountResolver::class);
        $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'clearing', 'mycoolpay', 'XAF'));

        $this->get("/admin/projects/{$projectId}/ledger/accounts")
            ->assertOk()
            ->assertSee('MyCoolPay');
    }

    #[Test]
    public function the_movements_of_one_account_are_opened(): void
    {
        [, $projectId] = $this->project();
        $this->signIn();

        $wallet = $this->wallet($projectId, 'PARTY-9', 'mycoolpay');

        $this->get("/admin/projects/{$projectId}/ledger/accounts?kind=wallet&reference={$wallet->walletId}&currency=XAF")
            ->assertOk()
            ->assertSee('Les mouvements du compte')
            ->assertSee('5 000');
    }

    #[Test]
    public function an_account_of_another_organization_is_not_found(): void
    {
        [$organizationId, $projectId] = $this->project();
        [, $otherProjectId] = $this->project();
        $this->signIn($organizationId, OrganizationRole::Viewer);

        $this->get("/admin/projects/{$projectId}/ledger/accounts")->assertOk();
        $this->get("/admin/projects/{$otherProjectId}/ledger/accounts")->assertNotFound();
    }

    /**
     * @return array{0: string, 1: string} the organization id, the project id
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Org '.Str::random(6)),
            actor: 'service:test',
            correlationId: null,
        );
        $project = app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet'),
            actor: 'service:test',
            correlationId: null,
        )->project;

        return [$organization->id, $project->id];
    }

    private function wallet(string $projectId, string $partyRef, string $providerCode): WalletBalanceView
    {
        $party = app(PartyFinder::class)->findOrCreate(new FindOrCreatePartyCommand(
            projectId: $projectId,
            externalRef: $partyRef,
            type: 'merchant',
        ));

        return app(CreditWallet::class)->credit(new CreditWalletCommand(
            projectId: $projectId,
            partyId: $party->id,
            amountMinor: 5_000,
            currency: 'XAF',
            externalRef: null,
            idempotencyKey: (string) Str::uuid(),
            origin: new WalletCreditOrigin(WalletCreditSource::Clearing, $providerCode),
        ));
    }

    private function customer(string $projectId, string $externalRef): \Core\Billing\Contracts\CustomerView
    {
        return app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId,
            externalRef: $externalRef,
            displayName: 'Agence du Nord',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'service:test',
            correlationId: null,
        ));
    }

    /**
     * The words the operator reads — the attributes of the links are not
     * words, they are addresses.
     *
     * @param \Illuminate\Testing\TestResponse<\Illuminate\Http\Response> $response
     */
    private function visibleText(\Illuminate\Testing\TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? strip_tags($content) : '';
    }

    /**
     * Signs an operator in: a platform account, or a member of one
     * organization (which sees that organization only).
     */
    private function signIn(?string $organizationId = null, ?OrganizationRole $role = null): void
    {
        $created = OperatorFactory::create(
            uniqid('op-', true).'@example.com',
            $organizationId === null ? OperatorRole::PlatformAdmin : null,
        );
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        if ($organizationId !== null && $role !== null) {
            app(OrganizationMembershipRepository::class)->save(new OrganizationMembership(
                id: (string) Str::uuid(),
                organizationId: $organizationId,
                operatorId: $created,
                role: $role,
                createdAt: new DateTimeImmutable,
            ));
        }

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
