<?php

declare(strict_types=1);

namespace Core\Wallet\Tests\Integration;

use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Wallet\Contracts\CreditWallet;
use Core\Wallet\Contracts\CreditWalletCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * F-12 — the money routes of the wallet and the strict typing of their commands.
 *
 * Laravel's `integer` rule validates `"1000"` without casting it: `validated()`
 * returned the string, the `int` command was built under
 * `declare(strict_types=1)` and the call ended in a `TypeError` (HTTP 500). The
 * amount is now converted by the request itself; what is not a valid integer
 * is a 422.
 */
final class StrictAmountInputHttpTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    #[Test]
    public function a_withdrawal_amount_sent_as_a_string_is_debited_with_its_integer_value(): void
    {
        ['project_id' => $projectId, 'headers' => $headers] = $this->projectWithFunds('merchant', 10_000);

        $this->postJson('/v1/wallets/withdrawals', [
            'party' => 'merchant', 'amount' => '1000', 'currency' => 'XAF',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201)
            ->assertJsonPath('type', 'withdrawal')
            ->assertJsonPath('wallets.0.available_minor', 9_000);

        self::assertSame(1, DB::table('wallet_operations')->where('project_id', $projectId)->where('type', 'withdrawal')->where('total_minor', 1_000)->count());
    }

    #[Test]
    public function the_amount_of_each_transfer_leg_sent_as_a_string_is_moved_with_its_integer_value(): void
    {
        ['headers' => $headers] = $this->projectWithFunds('escrow', 10_000);

        $transfer = $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'legs' => [
                ['from' => 'escrow', 'to' => 'merchant', 'amount' => '4000'],
                ['from' => 'escrow', 'to' => 'platform', 'amount' => '1000'],
            ],
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201)
            ->assertJsonPath('type', 'transfer')
            ->assertJsonPath('total_minor', 5_000);

        $balances = collect((array) $transfer->json('wallets'))->pluck('available_minor', 'party')->all();
        self::assertSame(5_000, $balances['escrow'] ?? null);
        self::assertSame(4_000, $balances['merchant'] ?? null);
        self::assertSame(1_000, $balances['platform'] ?? null);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAnInteger(): array
    {
        return [
            'letters' => ['abc'],
            'decimal string' => ['12.5'],
            'empty string' => [''],
            'boolean' => [true],
            'decimal number' => [1000.5],
        ];
    }

    #[Test]
    #[DataProvider('notAnInteger')]
    public function an_amount_that_is_not_an_integer_is_a_422_never_a_500(mixed $amount): void
    {
        ['headers' => $headers] = $this->projectWithFunds('merchant', 10_000);

        $this->postJson('/v1/wallets/withdrawals', [
            'party' => 'merchant', 'amount' => $amount, 'currency' => 'XAF',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'legs' => [['from' => 'merchant', 'to' => 'platform', 'amount' => $amount]],
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['legs.0.amount']);

        // Nothing moved: no operation was recorded.
        self::assertSame(0, DB::table('wallet_operations')->count());
    }

    /**
     * A project with a key and a wallet holding `$amount` for the party `$ref`.
     *
     * @return array{project_id: string, headers: array<string, string>}
     */
    private function projectWithFunds(string $ref, int $amount): array
    {
        $project = $this->makeProject('Wallet');
        $party = app(PartyFinder::class)->findOrCreate(new FindOrCreatePartyCommand($project['project_id'], $ref, 'wallet_holder'));
        app(CreditWallet::class)->credit(new CreditWalletCommand(
            $project['project_id'], $party->id, $amount, 'XAF', null, (string) Str::uuid(), $ref,
        ));

        return [
            'project_id' => $project['project_id'],
            'headers' => $this->keyFor($project['project_id'], ['wallets:write', 'wallets:read']),
        ];
    }
}
