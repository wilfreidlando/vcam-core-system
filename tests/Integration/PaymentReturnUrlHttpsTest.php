<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit de sécurité 2026-10-29, écart #18 : `return_url` n'était validé que par `url`, qui accepte
 * des schémas exotiques que le prestataire réfléchit tels quels vers le navigateur du client.
 * Seul `https://` passe. (Pas de liste blanche par projet ici : `allowed_return_urls` vit dans le
 * module portail, et `payment` ne peut pas en dépendre — G3c.)
 */
final class PaymentReturnUrlHttpsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('refusedReturnUrls')]
    public function a_return_url_that_is_not_https_is_refused_with_a_422_and_nothing_is_created(string $returnUrl): void
    {
        [$token] = $this->project();

        $this->postJson('/v1/payments/paylink', $this->payload($returnUrl), $this->headers($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('return_url');

        self::assertSame(0, DB::table('payments')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedReturnUrls(): array
    {
        return [
            'http clair' => ['http://example.com/payments/return'],
            'javascript' => ['javascript://example.com/%0Aalert(1)'],
            'ftp' => ['ftp://example.com/return'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'sans schéma' => ['example.com/return'],
        ];
    }

    #[Test]
    public function an_https_return_url_is_accepted(): void
    {
        [$token, $projectId] = $this->project();
        app(ProviderApplicationRepository::class)->save(new ProviderApplication($projectId, 'my-coolpay', 'pub-return-1', 'test-private-key'));
        Http::fake([
            'my-coolpay.com/api/pub-return-1/paylink' => Http::response([
                'status' => 'success', 'transaction_ref' => 'tx-return-1', 'payment_url' => 'https://my-coolpay.com/pay/ret',
            ], 201),
        ]);

        $this->postJson('/v1/payments/paylink', $this->payload('https://example.com/payments/return'), $this->headers($token))
            ->assertStatus(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $returnUrl): array
    {
        return ['channel' => 'mtn_momo', 'amount' => 1_000, 'currency' => 'XAF', 'reason' => 'Order #1', 'return_url' => $returnUrl];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ['payments:write']), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }
}
