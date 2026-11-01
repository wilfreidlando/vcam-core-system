<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * Audit de robustesse 2026-10-29, écart #24 : l'URL de paiement vient du prestataire et
 * n'est pas validée. `href="javascript:…"` s'exécutait dans la session de l'opérateur de la
 * console. Seule une adresse `https://` devient un lien ; toute autre valeur est du texte.
 */
final class PaymentsPaymentUrlTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function an_https_payment_url_is_a_link(): void
    {
        $page = $this->pageFor('https://my-coolpay.com/pay/abc');

        self::assertStringContainsString('href="https://my-coolpay.com/pay/abc"', $page);
        self::assertStringContainsString('rel="noopener noreferrer"', $page);
    }

    #[Test]
    #[DataProvider('hostileUrls')]
    public function any_other_scheme_is_shown_as_text_and_never_as_a_link(string $url): void
    {
        $page = $this->pageFor($url);

        self::assertStringNotContainsString('href="'.e($url).'"', $page);
        self::assertStringNotContainsString('href="'.$url.'"', $page);
        self::assertDoesNotMatchRegularExpression('/href="\s*(javascript|data|vbscript):/i', $page);
        // The operator still sees what the provider sent, escaped.
        self::assertStringContainsString(e($url), $page);
        self::assertStringContainsString('Adresse non cliquable', $page);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'javascript majuscules' => ['JaVaScRiPt:alert(1)'],
            'espace devant' => [' javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'http clair' => ['http://my-coolpay.com/pay/abc'],
            'sans schéma' => ['//evil.example/pay'],
        ];
    }

    private function pageFor(string $paymentUrl): string
    {
        $this->signInToConsole();

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Url Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Url Project'), actor: 'service:test', correlationId: null)->project;

        $paymentId = (string) Str::uuid();
        app(PaymentRepository::class)->create(new Payment(
            id: $paymentId,
            projectId: $project->id,
            channel: 'mtn_momo',
            providerCode: 'my-coolpay',
            idempotencyKey: (string) Str::uuid(),
            providerTransactionRef: (string) Str::uuid(),
            amount: 100,
            currency: 'XAF',
            status: PaymentStatus::Pending,
            requiresAction: true,
            actionHint: null,
            paymentUrl: $paymentUrl,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
        ));

        $response = $this->get("/admin/payments/{$paymentId}?project_id={$project->id}")->assertOk();
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }
}
