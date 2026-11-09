<?php

declare(strict_types=1);

namespace Core\Portal\Tests\Integration;

use Core\Billing\Contracts\ApproveManualReceipt;
use Core\Billing\Contracts\ApproveManualReceiptCommand;
use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\RecordManualReceipt;
use Core\Billing\Contracts\RecordManualReceiptCommand;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0119 — le rechargement du quota par le client final, dans le
 * portail.
 *
 * Le client ne connaît pas le Core : il voit une phrase, des packs et
 * un bouton. Il achète, la facture s'affiche, il paie, et la limite
 * qu'il voit monte — parce que c'est la **limite effective** qui monte
 * (ADR-0117 §3).
 */
final class PortalPackPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const METER = 'whatsapp_messages';

    #[Test]
    public function le_portail_propose_le_pack_quand_le_quota_manque(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 100);

        $cookie = $this->openSession($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/usage')
            ->assertOk()
            ->assertSee('Recharger mon quota')
            ->assertSee('Vous avez épuisé vos 100 messages.')
            ->assertSee('Pack de 50 messages')
            ->assertSee('250');
    }

    #[Test]
    public function le_pack_est_propose_meme_quand_le_quota_va(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 10);

        $cookie = $this->openSession($token, 'AE0001');

        // ADR-0119 §4 — l'offre ne dépend pas de l'usage : le client
        // ne vérifie pas lui-même où il en est de son quota pour savoir
        // qu'il peut en acheter.
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/usage')
            ->assertOk()
            ->assertSee('Recharger mon quota')
            ->assertSee('Pack de 50 messages')
            ->assertSee('Il vous reste 90 messages sur 100.');
    }

    #[Test]
    public function un_pack_d_un_autre_projet_n_est_jamais_propose(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->consume($projectId, 'AE0001', 100);

        // Un autre projet, son client, son offre et **son** catalogue.
        [$otherToken, $otherProjectId] = $this->project();
        $this->customer($otherProjectId, 'BE0001');
        $this->plan($otherProjectId);
        $this->subscription($otherProjectId, 'BE0001');
        $this->catalogue($otherProjectId, label: 'Pack de 999 messages');

        $cookie = $this->openSession($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/usage')
            ->assertOk()
            ->assertDontSee('Pack de 999 messages')
            ->assertDontSee('Pack de 50 messages');
    }

    #[Test]
    public function la_page_ne_parle_pas_de_jargon(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 100);

        $cookie = $this->openSession($token, 'AE0001');

        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal/usage'));

        // Le **texte** lu par le client, sans les balises : les noms de
        // classes CSS ne sont pas du jargon.
        $text = (string) preg_replace('/<[^>]*>/', ' ', $html);

        foreach (['meter', 'entitlement', 'supplement', 'compteur', 'supplément'] as $mot) {
            self::assertStringNotContainsStringIgnoringCase($mot, $text, sprintf('Le portail parle de « %s » au client.', $mot));
        }
    }

    #[Test]
    public function l_achat_dans_le_portail_produit_une_facture_a_payer(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 100);

        $cookie = $this->openSession($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);
        $nonce = $this->nonceOf($cookie);

        $response = $this->withUnencryptedCookie('portal', $cookie)
            ->post('/portal/packs', ['_csrf' => $csrf, 'pack' => 'pack-50', 'nonce' => $nonce]);

        $invoiceId = DB::table('invoices')->latest('created_at')->value('id');
        self::assertIsString($invoiceId);

        // Le client est envoyé vers la facture — le chemin de paiement
        // qui existe déjà.
        $response->assertRedirect('/portal/invoices/'.$invoiceId);

        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/invoices/'.$invoiceId)
            ->assertOk()
            ->assertSee('Pack de 50 messages')
            ->assertSee('Payer cette facture');

        // La commande n'accorde rien : la limite n'a pas bougé.
        self::assertSame('pending', DB::table('subscription_supplements')->value('status'));
        self::assertSame(100, $this->limit($projectId));
    }

    #[Test]
    public function le_reglement_de_la_facture_augmente_la_limite_effective(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 100);

        $cookie = $this->openSession($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/packs', [
            '_csrf' => $this->csrfOf($cookie),
            'pack' => 'pack-50',
            'nonce' => $this->nonceOf($cookie),
        ])->assertRedirect();

        $invoiceId = DB::table('invoices')->latest('created_at')->value('id');
        self::assertIsString($invoiceId);

        $this->settle($projectId, $invoiceId, 250_000);

        // Le supplément est actif : la limite effective a monté.
        self::assertSame('active', DB::table('subscription_supplements')->value('status'));
        self::assertSame(150, $this->limit($projectId));

        // Et le portail montre la même limite — une seule source de
        // vérité (ADR-0117 §3).
        $this->withUnencryptedCookie('portal', $cookie)->get('/portal/usage')
            ->assertOk()
            ->assertSee('150');
    }

    #[Test]
    public function un_rejeu_de_la_meme_cle_achete_une_seule_fois(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 100);

        $cookie = $this->openSession($token, 'AE0001');
        $csrf = $this->csrfOf($cookie);
        $nonce = $this->nonceOf($cookie);

        // Le double clic : le même formulaire, deux fois.
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/packs', ['_csrf' => $csrf, 'pack' => 'pack-50', 'nonce' => $nonce])->assertRedirect();
        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/packs', ['_csrf' => $csrf, 'pack' => 'pack-50', 'nonce' => $nonce])->assertRedirect();

        self::assertSame(1, DB::table('subscription_supplements')->count());
        self::assertSame(1, DB::table('event_outbox')->where('type', 'subscription.supplement_purchased')->count());
    }

    #[Test]
    public function un_pack_inexistant_repond_par_une_phrase_claire(): void
    {
        [$token, $projectId] = $this->project();
        $this->customer($projectId, 'AE0001');
        $this->plan($projectId);
        $this->subscription($projectId, 'AE0001');
        $this->catalogue($projectId);
        $this->consume($projectId, 'AE0001', 100);

        $cookie = $this->openSession($token, 'AE0001');

        $this->withUnencryptedCookie('portal', $cookie)->post('/portal/packs', [
            '_csrf' => $this->csrfOf($cookie),
            'pack' => 'pack-retire',
            'nonce' => $this->nonceOf($cookie),
        ])
            ->assertStatus(422)
            ->assertSee('plus disponible');

        self::assertSame(0, DB::table('subscription_supplements')->count());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{0: string, 1: string}
     */
    private function project(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project->id, ['portal:write']), actor: 'service:test', correlationId: null);

        return [$issued->plaintextToken, $project->id];
    }

    private function customer(string $projectId, string $ref): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId, externalRef: $ref, displayName: 'Auto-école Soleil', email: null, phone: null,
            locale: 'fr', metadata: [], expectedVersion: null, actor: 'test', correlationId: null,
        ));
    }

    private function plan(string $projectId): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId, code: 'pro', name: 'Pro', description: null, visibility: 'public',
            sortOrder: 0, actor: 'test', correlationId: null,
        ));
        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId, planCode: 'pro', priceMinor: 25_000, currency: 'XAF', intervalUnit: 'month',
            intervalCount: 1, trialDays: 14, graceDays: 7, graceAccess: true,
            features: ['messages' => ['type' => 'limit', 'meter' => self::METER, 'limit' => 100, 'reset' => 'period', 'overage' => 'block']],
            actor: 'test', correlationId: null,
        ));
        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId, planCode: 'pro', version: $version->version, actor: 'test', correlationId: null,
        ));
    }

    private function subscription(string $projectId, string $ref): void
    {
        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId, customerRef: $ref, planCode: 'pro', trialDays: 14, startAt: null,
            idempotencyKey: 'sub-'.$ref, actor: 'test', correlationId: null,
        ));
    }

    /**
     * Le catalogue du projet : un pack de cinquante messages à 5 000
     * l'unité.
     */
    private function catalogue(string $projectId, string $label = 'Pack de 50 messages'): void
    {
        app(ManageBillingSettings::class)->save(new SaveBillingSettingsCommand(
            projectId: $projectId,
            timezone: 'Africa/Douala',
            defaultCurrency: 'XAF',
            renewalLeadDays: 7,
            incompleteExpiryHours: 72,
            invoicePrefix: 'FACT',
            defaultTaxRateBps: 0,
            manualReceiptApprovalThresholdMinor: 0,
            meterMappings: [],
            refundFailedUsage: true,
            requireCustomerRef: false,
            actor: 'test',
            supplementPacks: [[
                'code' => 'pack-50',
                'meter' => self::METER,
                'quantity' => 50,
                'unit_price_minor' => 5_000,
                'label' => $label,
            ]],
        ), AccessScope::platform());
    }

    private function consume(string $projectId, string $ref, int $quantity): void
    {
        app(ConsumeUsage::class)->consume(new ConsumeUsageCommand(
            projectId: $projectId,
            customerRef: $ref,
            meter: self::METER,
            quantity: $quantity,
            idempotencyKey: 'usage-'.$ref.'-'.$quantity,
            source: 'api',
        ));
    }

    /**
     * L'argent arrive : la facture est réglée par un reçu hors ligne, et
     * le supplément devient actif dans cette transaction (ADR-0117 §2).
     */
    private function settle(string $projectId, string $invoiceId, int $amountMinor): void
    {
        $receipt = app(RecordManualReceipt::class)->record(new RecordManualReceiptCommand(
            projectId: $projectId,
            invoiceId: $invoiceId,
            method: 'bank_transfer',
            reference: 'VIR-2026-0001',
            amountMinor: $amountMinor,
            currency: 'XAF',
            receivedAt: '2026-11-02T08:00:00Z',
            recordedBy: '77777777-7777-4777-8777-777777777777',
            actor: 'operator:77777777-7777-4777-8777-777777777777',
            correlationId: null,
        ));

        if ($receipt->status === 'pending') {
            app(ApproveManualReceipt::class)->approve(new ApproveManualReceiptCommand(
                projectId: $projectId,
                receiptId: $receipt->id,
                approver: '77777777-7777-4777-8777-777777777777',
                approved: true,
                actor: 'operator:77777777-7777-4777-8777-777777777777',
                correlationId: null,
            ));
        }
    }

    private function limit(string $projectId): ?int
    {
        $view = app(GetEntitlements::class)->get(
            new GetEntitlementsCommand($projectId, 'AE0001'),
            AccessScope::platform(),
        );

        self::assertNotNull($view);

        foreach ($view->entitlements as $entitlement) {
            if ($entitlement->key === 'messages') {
                return $entitlement->limit;
            }
        }

        self::fail('The plan carries no feature named "messages".');
    }

    private function openSession(string $token, string $ref): string
    {
        $url = $this->jsonString($this->postJson('/v1/portal/sessions', ['customer_ref' => $ref, 'flow' => 'manage'], $this->auth($token))->assertStatus(201), 'url');
        $response = $this->get((string) parse_url($url, PHP_URL_PATH));
        $cookie = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'portal');

        return $cookie?->getValue() ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function jsonString($response, string $key): string
    {
        $value = $response->json($key);
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function html($response): string
    {
        // Larastan models TestResponse's forwarded getContent() as static.
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }

    private function csrfOf(string $cookie): string
    {
        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal'));
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    private function nonceOf(string $cookie): string
    {
        $html = $this->html($this->withUnencryptedCookie('portal', $cookie)->get('/portal/usage'));
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $html, $m);

        return $m[1] ?? '';
    }
}
