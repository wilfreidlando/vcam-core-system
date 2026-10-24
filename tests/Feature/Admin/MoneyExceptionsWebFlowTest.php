<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Payment\Contracts\RecordMoneyException;
use Core\Payment\Contracts\RecordMoneyExceptionCommand;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * ADR-0077 §4 — the money exceptions screen, through real HTTP form
 * submissions: four eyes above the threshold, the author never approves
 * their own resolution, the ledger movement is booked once, on approval.
 */
final class MoneyExceptionsWebFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000077e1';

    #[Test]
    public function above_the_threshold_a_second_finance_operator_approves_and_the_ledger_is_written_once(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['payment.exceptions.approval_threshold_minor' => 50_000]);
        $exceptionId = $this->openException(bookedAmount: 60_000);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->get(route('admin.money-exceptions.index'))
            ->assertOk()
            ->assertSee('Écart de montant')
            ->assertSee('Traiter');

        $this->get(route('admin.money-exceptions.show', $exceptionId))
            ->assertOk()
            ->assertSee('Passé en revenu du projet');

        $this->post(route('admin.money-exceptions.resolve', $exceptionId), [
            'resolution' => 'recognized_as_revenue',
            'note' => 'Complément volontaire du client, confirmé par téléphone.',
        ])->assertRedirect(route('admin.money-exceptions.show', $exceptionId));

        self::assertSame('awaiting_approval', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));
        self::assertSame(0, $this->resolutionTransactions($exceptionId));

        // The author cannot approve their own resolution.
        $this->post(route('admin.money-exceptions.approve', $exceptionId))->assertSessionHasErrors('resolution');
        self::assertSame('awaiting_approval', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));

        $this->post(route('admin.logout'));
        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.money-exceptions.approve', $exceptionId))
            ->assertRedirect(route('admin.money-exceptions.show', $exceptionId));

        self::assertSame('resolved', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));
        self::assertSame(1, $this->resolutionTransactions($exceptionId));

        // Approving again books nothing more.
        $this->post(route('admin.money-exceptions.approve', $exceptionId))->assertSessionHasErrors('resolution');
        self::assertSame(1, $this->resolutionTransactions($exceptionId));

        self::assertSame(2, DB::table('audit_log')->where('resource', 'money_exception:'.$exceptionId)->count());
    }

    #[Test]
    public function below_the_threshold_one_decision_settles_the_exception_at_once(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['payment.exceptions.approval_threshold_minor' => 50_000]);
        $exceptionId = $this->openException(bookedAmount: 1_000);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.money-exceptions.resolve', $exceptionId), [
            'resolution' => 'refunded_outside',
            'reference' => 'MOMO-2026-10-24-0042',
            'note' => 'Différence remboursée au client par MoMo.',
        ])->assertRedirect(route('admin.money-exceptions.show', $exceptionId));

        self::assertSame('resolved', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));
        self::assertSame(1, $this->resolutionTransactions($exceptionId));
    }

    #[Test]
    public function a_refund_without_its_reference_is_refused_with_a_readable_message(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $exceptionId = $this->openException(bookedAmount: 1_000);

        $this->loginAndEnrollMfa(OperatorRole::FinanceOperator);

        $this->post(route('admin.money-exceptions.resolve', $exceptionId), [
            'resolution' => 'refunded_outside',
            'note' => 'Remboursé au client.',
        ])->assertSessionHasErrors('resolution');

        self::assertSame('open', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));
    }

    #[Test]
    public function the_auditor_reads_but_cannot_decide_and_support_cannot_see_the_queue(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $exceptionId = $this->openException(bookedAmount: 1_000);

        $this->loginAndEnrollMfa(OperatorRole::ReadOnlyAuditor);
        $this->get(route('admin.money-exceptions.show', $exceptionId))->assertOk()->assertDontSee('Enregistrer la décision');
        $this->post(route('admin.money-exceptions.resolve', $exceptionId), [
            'resolution' => 'recognized_as_revenue',
            'note' => 'Tentative sans droit.',
        ])->assertForbidden();

        $this->post(route('admin.logout'));
        $this->loginAndEnrollMfa(OperatorRole::SupportOperator);
        $this->get(route('admin.money-exceptions.index'))->assertForbidden();
    }

    private function openException(int $bookedAmount): string
    {
        $id = app(RecordMoneyException::class)->record(new RecordMoneyExceptionCommand(
            projectId: self::PROJECT_ID,
            kind: 'amount_mismatch',
            severity: 'high',
            amountMinor: $bookedAmount,
            currency: 'XAF',
            bookedAmountMinor: $bookedAmount,
            paymentId: null,
            subjectRef: null,
            summary: 'Paiement order-42 : demandé 5 000 XAF, le prestataire confirme un autre montant.',
        ));
        self::assertIsString($id);

        return $id;
    }

    private function resolutionTransactions(string $exceptionId): int
    {
        return DB::table('ledger_transactions')
            ->where('project_id', self::PROJECT_ID)
            ->where('idempotency_key', 'money_exception:'.$exceptionId.':resolution')
            ->count();
    }

    private function loginAndEnrollMfa(OperatorRole $role): void
    {
        $created = app(CreateOperator::class)->create(
            new CreateOperatorCommand(uniqid('op-', true).'@example.com', 'Ops', $role->value),
            actor: 'service:test',
            correlationId: null,
        );

        $operator = app(OperatorRepository::class)->find($created->operatorId);
        self::assertInstanceOf(Operator::class, $operator);

        $this->post('/admin/login', [
            'email' => $operator->email,
            'password' => $created->plaintextPassword,
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
