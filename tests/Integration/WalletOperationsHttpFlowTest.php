<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0065, end to end over HTTP against real PostgreSQL: a sandbox payment
 * credits an escrow wallet, a multi-leg transfer settles it between a
 * merchant and the platform, a withdrawal records a payout, and every
 * movement shows in the wallets' history — with idempotent replays,
 * insufficient funds rejected atomically, and sandbox/live isolation.
 */
final class WalletOperationsHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_payment_credit_is_settled_by_transfer_then_withdrawn_and_shows_in_history(): void
    {
        Http::preventStrayRequests();
        [$token] = $this->authenticatedProject(Environment::Sandbox);

        $this->creditBySandboxPayment($token, 'escrow', 50_000);

        $this->getJson('/v1/parties/escrow/wallets', $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.0.party', 'escrow')
            ->assertJsonPath('data.0.available_minor', 50_000);

        $key = (string) Str::uuid();
        $transfer = $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'description' => 'Settlement of booking ETR-7K3Q9',
            'metadata' => ['order' => 'ETR-7K3Q9'],
            'legs' => [
                ['from' => 'escrow', 'to' => 'merchant:01J9', 'amount' => 45_000, 'to_party_type' => 'merchant'],
                ['from' => 'escrow', 'to' => 'platform', 'amount' => 5_000],
            ],
        ], $this->auth($token) + ['Idempotency-Key' => $key]);

        $transfer->assertStatus(201)
            ->assertJsonPath('type', 'transfer')
            ->assertJsonPath('reference', $key)
            ->assertJsonPath('total_minor', 50_000)
            ->assertJsonPath('legs.0.to', 'merchant:01J9');
        $balances = collect((array) $transfer->json('wallets'))->pluck('available_minor', 'party')->all();
        self::assertSame(['escrow' => 0, 'merchant:01J9' => 45_000, 'platform' => 5_000], array_intersect_key($balances, ['escrow' => 0, 'merchant:01J9' => 0, 'platform' => 0]));

        // Replaying the same key moves nothing a second time.
        $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'description' => 'Settlement of booking ETR-7K3Q9',
            'metadata' => ['order' => 'ETR-7K3Q9'],
            'legs' => [
                ['from' => 'escrow', 'to' => 'merchant:01J9', 'amount' => 45_000, 'to_party_type' => 'merchant'],
                ['from' => 'escrow', 'to' => 'platform', 'amount' => 5_000],
            ],
        ], $this->auth($token) + ['Idempotency-Key' => $key])->assertStatus(201)->assertJsonPath('id', $transfer->json('id'));
        self::assertSame(1, DB::table('wallet_operations')->count());

        $withdrawal = $this->postJson('/v1/wallets/withdrawals', [
            'party' => 'merchant:01J9',
            'amount' => 20_000,
            'currency' => 'XAF',
            'description' => 'Withdrawal to MTN Mobile Money',
            'external_reference' => 'MP241014.1532.C12345',
        ], $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()]);
        $withdrawal->assertStatus(201)
            ->assertJsonPath('type', 'withdrawal')
            ->assertJsonPath('external_reference', 'MP241014.1532.C12345')
            ->assertJsonPath('wallets.0.available_minor', 25_000);

        $merchantWallet = $this->getJson('/v1/parties/merchant:01J9/wallets', $this->auth($token))->json('data.0.wallet_id');
        self::assertIsString($merchantWallet);

        $history = $this->getJson("/v1/wallets/{$merchantWallet}/transactions", $this->auth($token))->assertOk();
        $history->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', 'withdrawal')
            ->assertJsonPath('data.0.direction', 'debit')
            ->assertJsonPath('data.0.amount_minor', 20_000)
            ->assertJsonPath('data.1.type', 'transfer')
            ->assertJsonPath('data.1.direction', 'credit')
            ->assertJsonPath('data.1.reference', $key)
            ->assertJsonPath('data.1.description', 'Settlement of booking ETR-7K3Q9')
            ->assertJsonPath('data.1.metadata.order', 'ETR-7K3Q9');

        $escrowWallet = $this->getJson('/v1/parties/escrow/wallets', $this->auth($token))->json('data.0.wallet_id');
        self::assertIsString($escrowWallet);
        // One entry per leg: the two transfer debits, then the payment credit.
        $this->getJson("/v1/wallets/{$escrowWallet}/transactions", $this->auth($token))
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.type', 'transfer')
            ->assertJsonPath('data.0.direction', 'debit')
            ->assertJsonPath('data.2.type', 'payment')
            ->assertJsonPath('data.2.direction', 'credit');

        // The materialised balances still equal the ledger.
        foreach ([$merchantWallet, $escrowWallet] as $walletId) {
            $ledger = (int) DB::table('ledger_entries')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
                ->where('ledger_accounts.kind', 'wallet')->where('ledger_accounts.reference', $walletId)
                ->sum(DB::raw("CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END"));
            $balance = DB::table('wallet_balances')->where('wallet_id', $walletId)->value('available_minor');
            self::assertIsNumeric($balance);
            self::assertSame($ledger, (int) $balance);
        }
    }

    #[Test]
    public function an_insufficient_source_moves_nothing_at_all(): void
    {
        Http::preventStrayRequests();
        [$token] = $this->authenticatedProject(Environment::Sandbox);
        $this->creditBySandboxPayment($token, 'escrow', 10_000);

        $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'legs' => [
                ['from' => 'escrow', 'to' => 'merchant', 'amount' => 8_000],
                ['from' => 'escrow', 'to' => 'platform', 'amount' => 3_000],
            ],
        ], $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://errors.core-system.internal/insufficient-funds');

        $this->getJson('/v1/parties/escrow/wallets', $this->auth($token))->assertJsonPath('data.0.available_minor', 10_000);
        self::assertSame(0, DB::table('wallet_operations')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'wallet_transfer')->count());
    }

    #[Test]
    public function a_mutation_requires_an_idempotency_key_and_the_write_scope(): void
    {
        [$token] = $this->authenticatedProject(Environment::Sandbox);
        $body = ['currency' => 'XAF', 'legs' => [['from' => 'a', 'to' => 'b', 'amount' => 1]]];

        $this->postJson('/v1/wallets/transfers', $body, $this->auth($token))->assertStatus(400);

        [$readOnly] = $this->authenticatedProject(Environment::Sandbox, ['wallets:read']);
        $this->postJson('/v1/wallets/transfers', $body, $this->auth($readOnly) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(403);
    }

    #[Test]
    public function a_party_cannot_pay_itself(): void
    {
        [$token] = $this->authenticatedProject(Environment::Sandbox);

        $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'legs' => [['from' => 'escrow', 'to' => 'escrow', 'amount' => 100]],
        ], $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
    }

    #[Test]
    public function a_live_key_never_reaches_a_sandbox_wallet(): void
    {
        Http::preventStrayRequests();
        [$sandboxToken, $projectId] = $this->authenticatedProject(Environment::Sandbox);
        $this->creditBySandboxPayment($sandboxToken, 'escrow', 5_000);
        $walletId = $this->getJson('/v1/parties/escrow/wallets', $this->auth($sandboxToken))->json('data.0.wallet_id');
        self::assertIsString($walletId);

        $liveToken = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($projectId, Environment::Live, ApiKeyScopes::ALL, replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        )->plaintextToken;

        $this->getJson('/v1/parties/escrow/wallets', $this->auth($liveToken))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/v1/wallets/{$walletId}/balance", $this->auth($liveToken))->assertNotFound();
        $this->getJson("/v1/wallets/{$walletId}/transactions", $this->auth($liveToken))->assertNotFound();
        $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'legs' => [['from' => 'escrow', 'to' => 'merchant', 'amount' => 1_000]],
        ], $this->auth($liveToken) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        // The sandbox credit landed in the sandbox clearing account, never the live one.
        self::assertSame(0, DB::table('ledger_accounts')->where('project_id', $projectId)->where('kind', 'clearing')->where('reference', '')->count());
        self::assertSame(1, DB::table('ledger_accounts')->where('project_id', $projectId)->where('kind', 'clearing')->where('reference', 'sandbox')->count());
    }

    private function creditBySandboxPayment(string $token, string $recipient, int $amount): void
    {
        $paymentId = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => $amount,
            'currency' => 'XAF',
            'reason' => 'Sandbox order',
            'wallet_recipient_external_ref' => $recipient,
        ], $this->auth($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('id');
        self::assertIsString($paymentId);

        $this->postJson("/v1/sandbox/payments/{$paymentId}/simulate", ['status' => 'succeeded'], $this->auth($token) + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: string}
     */
    private function authenticatedProject(Environment $environment, array $scopes = ApiKeyScopes::ALL): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Test Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Test Project'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, $environment, $scopes, replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        );

        return [$issued->plaintextToken, $project->id];
    }
}
