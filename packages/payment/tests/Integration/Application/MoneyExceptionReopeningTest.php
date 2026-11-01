<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Application;

use Core\Payment\Contracts\CheckPaymentInvariants;
use Core\Payment\Contracts\ResolveMoneyException;
use Core\Payment\Contracts\ResolveMoneyExceptionCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Core\Support\Access\AccessScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-0077 §3, ADR-0078 §2 — a closed invariant violation must be
 * able to open again.
 *
 * The unique index on `(payment_id, kind)` used to cover every row, the
 * resolved ones included: once an operator settled the exception a
 * succeeded payment without settlement had opened, no new exception
 * could ever be recorded for that payment — the hourly control kept
 * finding the violation, kept trying to open it, and the repository
 * swallowed the constraint violation. The queue then showed "nothing to
 * open" for a payment that was still broken, and the control passed
 * silently.
 */
final class MoneyExceptionReopeningTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = '01a0a1cc-0000-7000-8000-0000000000d1';

    #[Test]
    public function a_settled_violation_opens_a_new_exception_on_the_next_pass(): void
    {
        // A succeeded payment with no settlement at all: the invariant
        // is broken.
        app(PaymentRepository::class)->create($this->payment(PaymentStatus::Succeeded, 2_000));

        $first = app(CheckPaymentInvariants::class)->check(2);
        self::assertSame(1, $first->exceptionsOpened);

        $exceptionId = DB::table('money_exceptions')->where('project_id', self::PROJECT_ID)->value('id');
        self::assertIsString($exceptionId);

        // The operator settles it. Nothing was booked on the pool (a
        // control failure books no money), so the resolution moves
        // nothing.
        app(ResolveMoneyException::class)->resolve(new ResolveMoneyExceptionCommand(
            exceptionId: $exceptionId,
            resolution: 'no_action',
            operatorId: '01a0a1cc-0000-7000-8000-0000000000ff',
            reference: null,
            note: 'Rapprochement repris, la cause est suivie à part.',
        ), AccessScope::platform());

        self::assertSame('resolved', DB::table('money_exceptions')->where('id', $exceptionId)->value('status'));

        // The payment is still broken: the next pass must find it again
        // and open A NEW exception — the queue keeps the trace.
        $second = app(CheckPaymentInvariants::class)->check(2);

        self::assertSame(1, $second->exceptionsOpened);
        self::assertSame(2, DB::table('money_exceptions')->where('project_id', self::PROJECT_ID)->count());
        self::assertSame(1, DB::table('money_exceptions')
            ->where('project_id', self::PROJECT_ID)
            ->where('status', 'resolved')
            ->count());
        self::assertSame(1, DB::table('money_exceptions')
            ->where('project_id', self::PROJECT_ID)
            ->where('status', 'open')
            ->count());
    }

    #[Test]
    public function two_open_exceptions_are_never_opened_for_the_same_payment_and_kind(): void
    {
        app(PaymentRepository::class)->create($this->payment(PaymentStatus::Succeeded, 2_000));

        // The payment is still broken: the control must NOT pile a
        // second OPEN exception on it.
        app(CheckPaymentInvariants::class)->check(2);
        app(CheckPaymentInvariants::class)->check(2);

        self::assertSame(1, DB::table('money_exceptions')
            ->where('project_id', self::PROJECT_ID)
            ->where('status', 'open')
            ->count());
    }

    /**
     * The `down()` of the migration that replaced the FULL unique index by
     * a conditional one puts the full index back — the very index it
     * replaces. It can therefore only apply to a base without
     * duplicates: a `resolved` row and an `open` row for the same
     * `(payment_id, kind)` are legal since that migration, and the full
     * index refuses them.
     *
     * The failure is ATOMIC and leaves the base unchanged: PostgreSQL
     * supports transactional DDL (`PostgresGrammar::$transactions = true`)
     * and Laravel wraps BOTH `up()` and `down()` in a transaction
     * (`Migrator::runMigration()`), so the conditional index dropped by
     * the first statement of the `down()` is restored.
     */
    #[Test]
    public function a_failing_rollback_leaves_the_conditional_index_in_place(): void
    {
        $this->twoExceptionsForTheSamePaymentAndKind();

        $before = $this->indexDefinition();
        self::assertStringContainsString("status <> 'resolved'", $before);

        $migration = $this->reopeningMigration();

        // The two properties the atomicity rests on: the framework wraps
        // the migration method in a transaction when the connection
        // supports transactional DDL and the migration asks for it —
        // exactly what `Migrator::runMigration()` checks.
        self::assertTrue($migration->withinTransaction);
        self::assertTrue($this->schemaGrammar()->supportsSchemaTransactions());

        if (! method_exists($migration, 'down')) {
            throw new RuntimeException('The migration must have a down() method.');
        }

        $failed = false;

        try {
            // Exactly what `Migrator::runMigration()` does: the migration
            // method runs inside a transaction.
            DB::transaction(function () use ($migration): void {
                $migration->down();
            });
        } catch (QueryException) {
            $failed = true;
        }

        self::assertTrue($failed, 'The down() must refuse a base that holds a duplicate.');
        self::assertSame(
            $before,
            $this->indexDefinition(),
            'A failed rollback must leave the base unchanged.',
        );
    }

    private function payment(PaymentStatus $status, int $amount): Payment
    {
        $id = (string) Str::uuid();

        return new Payment(
            id: $id, projectId: self::PROJECT_ID, channel: 'mtn_momo', providerCode: 'my-coolpay',
            idempotencyKey: 'order-'.substr($id, 0, 8), providerTransactionRef: 'tx-'.$id, amount: $amount, currency: 'XAF',
            status: $status, requiresAction: false, actionHint: null, paymentUrl: null, errorMessage: null, returnUrl: null,
            walletRecipientExternalRef: null,
        );
    }

    /**
     * The migration that replaced the full unique index by a conditional
     * one.
     */
    private function reopeningMigration(): Migration
    {
        $migration = require base_path(
            'packages/payment/database/migrations/2026_10_27_140000_allow_reopening_a_resolved_money_exception.php'
        );

        if (! $migration instanceof Migration) {
            throw new RuntimeException('The migration file must return a Migration instance.');
        }

        return $migration;
    }

    /**
     * The grammar the migrator reads to know whether the connection
     * supports transactional DDL — the very same lookup
     * `Migrator::getSchemaGrammar()` does.
     */
    private function schemaGrammar(): Grammar
    {
        $connection = DB::connection();

        // The builder installs the default grammar when the connection
        // has none yet — what `Migrator::getSchemaGrammar()` relies on.
        $connection->getSchemaBuilder();

        return $connection->getSchemaGrammar();
    }

    /**
     * Two exceptions for the same payment and the same kind: one settled,
     * one still open — legal since the conditional index, refused by the
     * full one.
     */
    private function twoExceptionsForTheSamePaymentAndKind(): void
    {
        $paymentId = (string) Str::uuid();

        DB::table('money_exceptions')->insert([
            [
                'id' => (string) Str::uuid(),
                'project_id' => self::PROJECT_ID,
                'kind' => 'amount_mismatch',
                'severity' => 'high',
                'amount_minor' => 1_000,
                'currency' => 'XAF',
                'booked_amount_minor' => 1_000,
                'payment_id' => $paymentId,
                'summary' => 'Exception déjà réglée.',
                'status' => 'resolved',
                'resolution' => 'refunded_outside',
                'resolved_by' => (string) Str::uuid(),
                'approved_by' => null,
            ],
            [
                'id' => (string) Str::uuid(),
                'project_id' => self::PROJECT_ID,
                'kind' => 'amount_mismatch',
                'severity' => 'high',
                'amount_minor' => 1_000,
                'currency' => 'XAF',
                'booked_amount_minor' => 1_000,
                'payment_id' => $paymentId,
                'summary' => 'Exception encore ouverte.',
                'status' => 'open',
                'resolution' => null,
                'resolved_by' => null,
                'approved_by' => null,
            ],
        ]);
    }

    /**
     * The definition of the index this migration owns.
     */
    private function indexDefinition(): string
    {
        $definition = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('indexname', 'money_exceptions_payment_kind_unique')
            ->value('indexdef');

        if (! is_string($definition)) {
            throw new RuntimeException('The index must exist.');
        }

        return $definition;
    }
}
