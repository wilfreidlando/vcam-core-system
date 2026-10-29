<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Presentation\Http;

use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * F-12 — `POST /v1/payments/{paylink,payin}` is a money route. Laravel's
 * `integer` rule validates `"5000"` without casting it: `validated()` returned
 * the string, `StartPaymentCommand::$amount` is an `int` under
 * `declare(strict_types=1)` and the call ended in a `TypeError` (HTTP 500), with
 * the `Idempotency-Key` already consumed. The amount is now converted by the
 * request itself; what is not a valid integer is a 422.
 */
final class StrictAmountInputTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    #[Test]
    public function an_amount_sent_as_a_string_starts_the_payment_with_its_integer_value(): void
    {
        Http::fake([
            'my-coolpay.com/api/pub-strict-1/paylink' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-strict-1',
                'payment_url' => 'https://my-coolpay.com/pay/strict',
            ], 201),
        ]);
        $headers = $this->headersOfProjectWithApplication('pub-strict-1');
        $headers += ['Idempotency-Key' => (string) Str::uuid()];

        $first = $this->postJson('/v1/payments/paylink', $this->body('5000'), $headers)
            ->assertStatus(201)
            ->assertJsonPath('amount', 5000)
            ->assertJsonPath('payment_url', 'https://my-coolpay.com/pay/strict');

        // The replay of the same key answers the same payment — not a 500.
        $this->postJson('/v1/payments/paylink', $this->body('5000'), $headers)
            ->assertStatus(201)
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('amount', 5000);
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
        Http::preventStrayRequests();
        $headers = $this->headersOfProjectWithApplication('pub-strict-2');

        foreach (['paylink', 'payin'] as $route) {
            $this->postJson("/v1/payments/{$route}", $this->body($amount), $headers + ['Idempotency-Key' => (string) Str::uuid()])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['amount']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(mixed $amount): array
    {
        return ['channel' => 'mtn_momo', 'amount' => $amount, 'currency' => 'XAF', 'reason' => 'Commande #1'];
    }

    /**
     * @return array<string, string>
     */
    private function headersOfProjectWithApplication(string $publicKey): array
    {
        $project = $this->makeProject('Payment');
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $project['project_id'],
            providerCode: 'my-coolpay',
            publicKey: $publicKey,
            privateKey: 'test-private-key',
        ));

        return $this->keyFor($project['project_id'], ['payments:write']);
    }
}
