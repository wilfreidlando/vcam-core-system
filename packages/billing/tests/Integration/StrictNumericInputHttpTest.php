<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use Core\Identity\Contracts\ApiKeyScopes;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * F-12 — the public `/v1` API of billing and the strict typing of its commands.
 *
 * Laravel's `integer` rule validates `"5000"` without casting it: before the fix,
 * `validated()` returned the string, the `int` command was built under
 * `declare(strict_types=1)` and the call ended in a `TypeError` (HTTP 500). A
 * numeric string is now converted by the request itself (`prepareForValidation`),
 * and anything that is not a valid integer is a 422 — never a 500.
 */
final class StrictNumericInputHttpTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    #[Test]
    public function a_plan_and_its_version_accept_numbers_sent_as_strings(): void
    {
        $headers = $this->authenticatedHeaders();

        $this->postJson('/v1/plans', ['code' => 'pro', 'name' => 'Pro', 'sort_order' => '10'], $headers)
            ->assertStatus(201)
            ->assertJsonPath('sort_order', 10);

        $this->postJson('/v1/plans/pro/versions', [
            'price_minor' => '5000',
            'currency' => 'XAF',
            'interval_unit' => 'month',
            'interval_count' => '1',
            'trial_days' => '14',
            'grace_days' => '7',
            'grace_access' => '0',
        ], $headers)
            ->assertStatus(201)
            ->assertJsonPath('price_minor', 5000)
            ->assertJsonPath('interval_count', 1)
            ->assertJsonPath('trial_days', 14)
            ->assertJsonPath('grace_days', 7)
            ->assertJsonPath('grace_access', false);
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
            'decimal number' => [12.5],
        ];
    }

    #[Test]
    #[DataProvider('notAnInteger')]
    public function a_plan_version_with_a_price_that_is_not_an_integer_is_a_422_never_a_500(mixed $price): void
    {
        $headers = $this->authenticatedHeaders();
        $this->postJson('/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $headers)->assertStatus(201);

        $this->postJson('/v1/plans/pro/versions', [
            'price_minor' => $price,
            'currency' => 'XAF',
            'interval_unit' => 'month',
            'interval_count' => '1',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_minor']);
    }

    #[Test]
    public function a_subscription_accepts_trial_days_as_a_string_and_is_cancelled_immediately_by_the_string_zero(): void
    {
        $headers = $this->authenticatedHeaders();
        $this->publishPlan($headers);
        $this->customer($headers, 'AE0001');

        $subscription = $this->postJson('/v1/subscriptions', [
            'customer_ref' => 'AE0001', 'plan_code' => 'pro', 'trial_days' => '3',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

        $anchor = $subscription->json('anchor_at');
        $trialEnd = $subscription->json('trial_ends_at');
        self::assertIsString($anchor);
        self::assertIsString($trialEnd);
        $seconds = (new DateTimeImmutable($trialEnd))->getTimestamp() - (new DateTimeImmutable($anchor))->getTimestamp();
        self::assertSame(3, (int) round($seconds / 86_400), 'the trial lasts the 3 days that were asked for');

        $id = self::text($subscription->json('id'));

        $this->postJson("/v1/subscriptions/{$id}/cancel", ['at_period_end' => '0'], $headers)
            ->assertOk()
            ->assertJsonPath('cancel_at_period_end', false)
            ->assertJsonPath('status', 'canceled');
    }

    #[Test]
    public function the_string_one_cancels_at_the_end_of_the_period(): void
    {
        $headers = $this->authenticatedHeaders();
        $this->publishPlan($headers);
        $this->customer($headers, 'AE0002');

        $id = self::text($this->postJson('/v1/subscriptions', [
            'customer_ref' => 'AE0002', 'plan_code' => 'pro',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('id'));

        $this->postJson("/v1/subscriptions/{$id}/cancel", ['at_period_end' => '1'], $headers)
            ->assertOk()
            ->assertJsonPath('cancel_at_period_end', true);
    }

    /**
     * « Empty » is a null for an optional field (the framework normalises it): not an error there.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function notAnIntegerNorEmpty(): array
    {
        return array_diff_key(self::notAnInteger(), ['empty string' => true]);
    }

    #[Test]
    #[DataProvider('notAnIntegerNorEmpty')]
    public function a_subscription_with_trial_days_that_are_not_an_integer_is_a_422_never_a_500(mixed $trialDays): void
    {
        $headers = $this->authenticatedHeaders();

        $this->postJson('/v1/subscriptions', [
            'customer_ref' => 'AE0001', 'plan_code' => 'pro', 'trial_days' => $trialDays,
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trial_days']);
    }

    #[Test]
    public function cancelling_with_a_value_that_is_not_a_boolean_is_a_422(): void
    {
        $headers = $this->authenticatedHeaders();

        $this->postJson('/v1/subscriptions/'.Str::uuid().'/cancel', ['at_period_end' => 'maybe'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['at_period_end']);
    }

    #[Test]
    public function an_invoice_accepts_quantities_amounts_and_rates_sent_as_strings(): void
    {
        $headers = $this->authenticatedHeaders();
        $this->customer($headers, 'AE0003');

        $this->postJson('/v1/invoices', [
            'customer_ref' => 'AE0003',
            'tax_rate_bps' => '0',
            'lines' => [
                ['description' => 'Heures de conduite', 'quantity' => '2', 'unit_amount_minor' => '50000', 'tax_rate_bps' => '1925'],
            ],
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201)
            ->assertJsonPath('lines.0.quantity', 2)
            ->assertJsonPath('lines.0.unit_amount_minor', 50_000)
            ->assertJsonPath('lines.0.amount_minor', 100_000)
            ->assertJsonPath('lines.0.tax_rate_bps', 1925)
            ->assertJsonPath('subtotal_minor', 100_000);
    }

    #[Test]
    #[DataProvider('notAnInteger')]
    public function an_invoice_line_that_is_not_an_integer_is_a_422_never_a_500(mixed $quantity): void
    {
        $headers = $this->authenticatedHeaders();
        $this->customer($headers, 'AE0003');

        $this->postJson('/v1/invoices', [
            'customer_ref' => 'AE0003',
            'lines' => [['description' => 'Heures', 'quantity' => $quantity, 'unit_amount_minor' => '50000']],
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.quantity']);
    }

    #[Test]
    public function a_usage_quantity_sent_as_a_string_is_counted_with_its_integer_value(): void
    {
        $headers = $this->authenticatedHeaders();
        $this->publishPlan($headers, withQuota: true);
        $this->customer($headers, 'AE0004');
        // A trial grants access at once: without it the contract waits for its first payment.
        $this->postJson('/v1/subscriptions', [
            'customer_ref' => 'AE0004', 'plan_code' => 'pro', 'trial_days' => '14',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

        $this->postJson('/v1/usage', [
            'customer_ref' => 'AE0004', 'meter' => 'students', 'quantity' => '2', 'idempotency_key' => 'u1',
        ], $headers)
            ->assertStatus(201)
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('used', 2)
            ->assertJsonPath('limit', 10)
            ->assertJsonPath('remaining', 8);
    }

    #[Test]
    #[DataProvider('notAnInteger')]
    public function a_usage_quantity_that_is_not_an_integer_is_a_422_never_a_500(mixed $quantity): void
    {
        $headers = $this->authenticatedHeaders();

        $this->postJson('/v1/usage', [
            'customer_ref' => 'AE0004', 'meter' => 'students', 'quantity' => $quantity, 'idempotency_key' => 'u2',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    /**
     * @return array<string, string>
     */
    private function authenticatedHeaders(): array
    {
        $project = $this->makeProject('Billing');

        return $this->keyFor($project['project_id'], ApiKeyScopes::ALL);
    }

    /**
     * @param array<string, string> $headers
     */
    private function publishPlan(array $headers, bool $withQuota = false): void
    {
        $this->postJson('/v1/plans', ['code' => 'pro', 'name' => 'Pro'], $headers)->assertStatus(201);
        $version = $this->postJson('/v1/plans/pro/versions', [
            'price_minor' => '5000',
            'currency' => 'XAF',
            'interval_unit' => 'month',
            'interval_count' => '1',
            'features' => $withQuota
                ? ['students' => ['type' => 'limit', 'meter' => 'students', 'limit' => 10, 'reset' => 'never', 'overage' => 'block']]
                : [],
        ], $headers)->assertStatus(201)->json('version');
        self::assertIsInt($version);

        $this->postJson("/v1/plans/pro/versions/{$version}/publish", [], $headers)->assertOk();
    }

    /**
     * @param array<string, string> $headers
     */
    private function customer(array $headers, string $ref): void
    {
        $this->putJson("/v1/customers/{$ref}", ['display_name' => 'Auto-école '.$ref], $headers)->assertSuccessful();
    }
}
