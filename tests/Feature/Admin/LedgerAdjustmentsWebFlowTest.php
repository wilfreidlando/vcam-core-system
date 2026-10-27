<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0076 §6 — the corrections screen, through real HTTP form
 * submissions: the console proposes, the console validates, and the
 * second pair of eyes is never the author.
 */
final class LedgerAdjustmentsWebFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The password the test itself chooses, at the activation of the
     * invitation (ADR-0081 S-07): the system never chooses one.
     */
    private const PASSWORD = 'a known passphrase';

    #[Test]
    public function a_finance_operator_proposes_a_correction_and_a_second_one_validates_it(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->get(route('admin.ledger-adjustments.index'))->assertOk();
        $this->get(route('admin.ledger-adjustments.new'))->assertOk();

        // The project first, then the transactions it may counter-pass:
        // ADR-0076 §5 — a correction names the transaction it reverses.
        $page = $this->get(route('admin.ledger-adjustments.new', ['project_id' => $projectId]));
        $page->assertOk();
        $page->assertSee($reversed);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Encaissement reçu sans facture, reclassé en revenu du projet.',
        ])->assertRedirect();

        $adjustment = DB::table('ledger_adjustments')->first();
        self::assertNotNull($adjustment);
        self::assertIsString($adjustment->id);
        self::assertSame('proposed', $adjustment->status);
        self::assertSame($reversed, $adjustment->reverses_transaction_id);
        self::assertSame(0, $this->adjustmentTransactions());

        // The author is never the validator: the second pair of eyes is
        // another person (ADR-0081 S-15).
        $this->post(route('admin.ledger-adjustments.approve', $adjustment->id))
            ->assertSessionHasErrors('adjustment');
        self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $adjustment->id)->value('status'));
        self::assertSame(0, $this->adjustmentTransactions());

        $this->post(route('admin.logout'));
        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.ledger-adjustments.approve', $adjustment->id))
            ->assertRedirect(route('admin.ledger-adjustments.show', $adjustment->id));

        self::assertSame('approved', DB::table('ledger_adjustments')->where('id', $adjustment->id)->value('status'));
        self::assertSame(1, $this->adjustmentTransactions());

        // The correction names the transaction it reverses, and the
        // transaction it produced points back at it (ADR-0076 §5).
        $transaction = DB::table('ledger_transactions')->where('type', 'adjustment')->first();
        self::assertNotNull($transaction);
        self::assertSame($reversed, $transaction->reverses_transaction_id);

        // Both the proposal and the validation left a trace.
        self::assertSame(2, DB::table('audit_log')->where('resource', 'ledger_adjustment:'.$adjustment->id)->count());

        // Approving again books nothing more.
        $this->post(route('admin.ledger-adjustments.approve', $adjustment->id));
        self::assertSame(1, $this->adjustmentTransactions());
    }

    #[Test]
    public function a_correction_without_a_motive_is_refused(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        self::assertSame(0, DB::table('ledger_adjustments')->count());
    }

    #[Test]
    public function a_correction_may_not_touch_the_account_of_a_party(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'wallet',
            'source_reference' => (string) Str::uuid(),
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Tentative de corriger le solde d\'une partie.',
        ])->assertSessionHasErrors('source_kind');

        self::assertSame(0, DB::table('ledger_adjustments')->count());
        self::assertSame(0, $this->adjustmentTransactions());
    }

    #[Test]
    public function a_correction_may_not_move_money_from_an_account_to_itself(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'clearing',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Tentative de corriger un compte par lui-même.',
        ])->assertSessionHasErrors('destination_kind');

        self::assertSame(0, DB::table('ledger_adjustments')->count());
        self::assertSame(0, $this->adjustmentTransactions());
    }

    #[Test]
    public function a_correction_may_not_reverse_a_transaction_of_another_project(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $foreign = $this->settlement($this->project());

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $foreign,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Tentative de contrer-passer une écriture d\'un autre projet.',
        ])->assertSessionHasErrors('reverses_transaction_id');

        self::assertSame(0, DB::table('ledger_adjustments')->count());
        self::assertSame(0, $this->adjustmentTransactions());
    }

    #[Test]
    public function the_auditor_reads_the_corrections_but_does_not_decide(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);

        $index = $this->get(route('admin.ledger-adjustments.index'));
        $index->assertOk();

        // The page never offers an action the account cannot take: the
        // link would lead to a 403 (ADR-0069).
        $index->assertDontSee(route('admin.ledger-adjustments.new'));

        $this->get(route('admin.ledger-adjustments.new'))->assertForbidden();

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Tentative sans droit.',
        ])->assertForbidden();

        self::assertSame(0, DB::table('ledger_adjustments')->count());
    }

    #[Test]
    public function a_correction_whose_trace_cannot_be_written_is_not_validated(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Encaissement reçu sans facture, reclassé en revenu du projet.',
        ])->assertRedirect();

        $adjustment = DB::table('ledger_adjustments')->first();
        self::assertNotNull($adjustment);
        self::assertIsString($adjustment->id);

        // The second pair of eyes: another person, never the author.
        $this->post(route('admin.logout'));
        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        // The audit journal is down: the decision must not be taken
        // silently, and the money must not move without a trace
        // (ADR-0076 §6).
        $this->app->bind(AuditLogger::class, fn (): AuditLogger => new class implements AuditLogger
        {
            public function record(AuditEntry $entry): void
            {
                throw new RuntimeException('the audit journal is down');
            }
        });

        $log = Log::spy();

        $this->post(route('admin.ledger-adjustments.approve', $adjustment->id))->assertStatus(500);

        $log->shouldHaveReceived('critical')->once();

        // Nothing was decided, and no money moved: the correction waits
        // for a decision that can be proven.
        self::assertSame('proposed', DB::table('ledger_adjustments')->where('id', $adjustment->id)->value('status'));
        self::assertSame(0, $this->adjustmentTransactions());
    }

    // ── ADR-0076 §6 — the trace of the proposal comes FIRST ─────────────

    /**
     * Below the double-validation threshold, the proposal itself moves
     * the money: the trace of the proposal must therefore be written
     * BEFORE the money moves, never after (ADR-0076 §6).
     */
    #[Test]
    public function the_trace_of_the_proposal_precedes_the_movement_of_money_it_triggers(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        config(['ledger.adjustments.approval_threshold_minor' => 10_000]);

        $projectId = $this->project();
        $reversed = $this->settlement($projectId);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        // The journal watches what the ledger already holds at the very
        // moment the trace is written.
        $journal = new class implements AuditLogger
        {
            /** @var array<string, int> */
            public array $adjustmentsAtTrace = [];

            public function record(AuditEntry $entry): void
            {
                $this->adjustmentsAtTrace[$entry->action] = DB::table('ledger_transactions')
                    ->where('type', 'adjustment')
                    ->count();
            }
        };

        $this->app->bind(AuditLogger::class, fn (): AuditLogger => $journal);

        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id' => $reversed,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Encaissement reçu sans facture, reclassé en revenu du projet.',
        ])->assertRedirect();

        // The trace of the proposal was written while the ledger held
        // NO correction yet: the proof came before the money.
        self::assertArrayHasKey('ledger_adjustment.proposed', $journal->adjustmentsAtTrace);
        self::assertSame(0, $journal->adjustmentsAtTrace['ledger_adjustment.proposed']);

        // ... and the money did move, under the threshold.
        self::assertSame(1, $this->adjustmentTransactions());

        $adjustment = DB::table('ledger_adjustments')->first();
        self::assertNotNull($adjustment);
        self::assertSame('approved', $adjustment->status);
    }

    // ── ADR-0076 §5 — an entry older than the hundred most recent ──────

    /**
     * The console proposes the hundred most recent entries of the
     * project; the free field reaches the older ones (B11).
     */
    #[Test]
    public function a_correction_may_counter_pass_an_entry_older_than_the_hundred_most_recent(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $projectId = $this->project();

        // The hundred most recent entries of the project...
        $this->datedSettlements($projectId, new DateTimeImmutable('now', new DateTimeZone('UTC')), 100);

        // ... and one that is older than all of them.
        $older = $this->datedSettlements(
            $projectId,
            new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC')),
            1,
        )[0];

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $page = $this->get(route('admin.ledger-adjustments.new', ['project_id' => $projectId]));
        $page->assertOk();

        // The list does not carry it: it holds the hundred most recent.
        $page->assertDontSee($older);

        // The free field does.
        $this->post(route('admin.ledger-adjustments.store'), [
            'project_id' => $projectId,
            'source_kind' => 'clearing',
            'source_reference' => '',
            'destination_kind' => 'revenue',
            'destination_reference' => '',
            'reverses_transaction_id_manual' => $older,
            'amount_minor' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Encaissement ancien, reclassé en revenu du projet.',
        ])->assertRedirect();

        $adjustment = DB::table('ledger_adjustments')->first();
        self::assertNotNull($adjustment);
        self::assertSame($older, $adjustment->reverses_transaction_id);
    }

    // ── The corrections screen is reachable from the console ────────────

    #[Test]
    public function the_corrections_screen_is_reachable_from_the_console(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        // An account that may read the corrections finds them in the
        // navigation — the screen was otherwise reachable only by typing
        // its URL.
        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.ledger-adjustments.index'));

        $this->post(route('admin.logout'));
        $this->loginAndEnrollMfa(OperatorRole::SupportOperator);

        // An account that may NOT read them does not even see the entry.
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.ledger-adjustments.index'));
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(
            new CreateOrganizationCommand('Organisation de test '.uniqid('', true)),
            actor: 'test:ledger-adjustments',
            correlationId: null,
        );

        return app(CreateProject::class)->create(
            new CreateProjectCommand($organization->id, 'Projet de test '.uniqid('', true)),
            actor: 'test:ledger-adjustments',
            correlationId: null,
        )->project->id;
    }

    /**
     * A settled transaction of the project — the one an operating
     * correction counter-passes (ADR-0076 §5).
     */
    private function settlement(string $projectId): string
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'revenue', '', 'XAF'));

        return app(LedgerPoster::class)->post(new PostLedgerTransactionCommand(
            projectId: $projectId,
            type: 'payment_settlement',
            externalRef: null,
            idempotencyKey: 'web-flow:'.Str::uuid(),
            entries: [
                new LedgerEntryInput($clearing->id, Direction::Debit, 1_000, 'XAF'),
                new LedgerEntryInput($revenue->id, Direction::Credit, 1_000, 'XAF'),
            ],
        ))->id;
    }

    private function adjustmentTransactions(): int
    {
        return DB::table('ledger_transactions')->where('type', 'adjustment')->count();
    }

    /**
     * Settlements written straight into the ledger, dated as asked.
     *
     * The ledger is append-only: a test cannot backdate an existing row,
     * so it writes the rows it needs — the same rows the poster would
     * have written.
     *
     * @return list<string> the ids of the transactions written
     */
    private function datedSettlements(string $projectId, DateTimeImmutable $createdAt, int $count): array
    {
        $accounts = app(AccountResolver::class);
        $clearing = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'clearing', '', 'XAF'));
        $revenue = $accounts->resolveOrCreate(new ResolveOrCreateAccountCommand($projectId, 'revenue', '', 'XAF'));

        $ids = [];
        $transactions = [];
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;

            $transactions[] = [
                'id' => $id,
                'project_id' => $projectId,
                'type' => 'payment_settlement',
                'external_ref' => null,
                'idempotency_key' => 'dated:'.$id,
                'created_at' => $createdAt,
            ];

            $entries[] = [
                'transaction_id' => $id,
                'account_id' => $clearing->id,
                'direction' => 'debit',
                'amount_minor' => 1_000,
                'currency' => 'XAF',
                'created_at' => $createdAt,
            ];
            $entries[] = [
                'transaction_id' => $id,
                'account_id' => $revenue->id,
                'direction' => 'credit',
                'amount_minor' => 1_000,
                'currency' => 'XAF',
                'created_at' => $createdAt,
            ];
        }

        DB::table('ledger_transactions')->insert($transactions);
        DB::table('ledger_entries')->insert($entries);

        return $ids;
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        // ADR-0081 S-07 — the account is created by an activation token:
        // the password is the one THIS test chooses, and the system
        // never chooses one.
        $email = uniqid('op-', true).'@example.com';

        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand($email, $role->value),
            actor: 'service:test',
            correlationId: null,
        );

        self::assertNotNull($invitation->token);

        app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: $invitation->token,
            name: 'Ops',
            password: self::PASSWORD,
        ));

        $operator = app(OperatorRepository::class)->findByEmail($email);
        self::assertInstanceOf(Operator::class, $operator);

        $this->post('/admin/login', [
            'email' => $operator->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('admin.mfa.enroll.show'));

        $enroll = $this->get('/admin/mfa/enroll');
        $content = $this->htmlBody($enroll);

        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $content, $secret) !== 1
            && preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $content, $secret) !== 1) {
            self::fail('Could not find the TOTP secret in the MFA enroll page.');
        }
        if (preg_match('/name="challenge_token"\s+value="([^"]+)"/', $content, $token) !== 1) {
            self::fail('Could not find the MFA challenge token.');
        }

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $token[1],
            'code' => (new Google2FA)->getCurrentOtp($secret[1]),
        ])->assertRedirect(route('admin.dashboard'));
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     */
    private function htmlBody(TestResponse $response): string
    {
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $response->getContent();

        return is_string($content) ? $content : '';
    }
}
