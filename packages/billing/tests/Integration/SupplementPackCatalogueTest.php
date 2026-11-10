<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

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
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPack;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPackCommand;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\InvalidBillingSettings;
use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0119 — le catalogue de packs et l'achat d'un pack.
 *
 * Le catalogue est une **déclaration du projet** : il vit dans ses
 * réglages, et il est refusé entier quand une de ses règles est
 * rompue. Le pack acheté produit un supplément et sa facture, comme
 * la route de l'opérateur — et le rejeu de la même clé n'achète qu'une
 * fois.
 */
final class SupplementPackCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const METER = 'whatsapp_messages';

    #[Test]
    public function un_pack_invalide_est_refuse(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);

        // Une quantité nulle : un pack n'ajoute rien.
        $this->expectException(InvalidBillingSettings::class);
        $this->declarePacks($projectId, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 0,
            'unit_price_minor' => 5_000,
            'label' => 'Pack de 50 messages',
        ]]);
    }

    #[Test]
    public function un_pack_au_prix_negatif_est_refuse(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);

        $this->expectException(InvalidBillingSettings::class);
        $this->declarePacks($projectId, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 50,
            'unit_price_minor' => -1,
            'label' => 'Pack de 50 messages',
        ]]);
    }

    /**
     * ADR-0121 — un pack à prix nul ne peut pas être vendu : le grand
     * livre refuse une ligne à zéro, et l'achat serait refusé avant
     * toute écriture. Une offre que personne ne peut acheter n'est pas
     * une offre.
     */
    #[Test]
    public function un_pack_a_prix_nul_est_refuse(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);

        $this->expectException(InvalidBillingSettings::class);
        $this->declarePacks($projectId, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 50,
            'unit_price_minor' => 0,
            'label' => 'Pack de 50 messages',
        ]]);
    }

    #[Test]
    public function un_pack_sans_libelle_est_refuse(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);

        $this->expectException(InvalidBillingSettings::class);
        $this->declarePacks($projectId, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 50,
            'unit_price_minor' => 5_000,
            'label' => '   ',
        ]]);
    }

    #[Test]
    public function un_pack_d_un_compteur_que_le_projet_ne_compte_pas_est_refuse(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);

        $this->expectException(InvalidBillingSettings::class);
        $this->declarePacks($projectId, [[
            'code' => 'pack-stock',
            'meter' => 'storage_mb',
            'quantity' => 50,
            'unit_price_minor' => 5_000,
            'label' => 'Pack de stockage',
        ]]);
    }

    #[Test]
    public function deux_packs_du_meme_code_sont_refuses(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);

        $this->expectException(InvalidBillingSettings::class);
        $this->declarePacks($projectId, [
            [
                'code' => 'pack-50',
                'meter' => self::METER,
                'quantity' => 50,
                'unit_price_minor' => 5_000,
                'label' => 'Pack de 50 messages',
            ],
            [
                'code' => 'pack-50',
                'meter' => self::METER,
                'quantity' => 100,
                'unit_price_minor' => 5_000,
                'label' => 'Pack de 100 messages',
            ],
        ]);
    }

    #[Test]
    public function le_catalogue_appartient_au_projet_qui_l_a_declare(): void
    {
        $mine = $this->project();
        $theirs = $this->project();

        $this->plan($mine);
        $this->plan($theirs);

        $this->declarePacks($theirs, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 50,
            'unit_price_minor' => 5_000,
            'label' => 'Pack de 50 messages',
        ]]);

        // Le catalogue de l'autre projet n'est pas le mien.
        $settings = app(ManageBillingSettings::class)->get($mine, AccessScope::platform());

        self::assertNotNull($settings);
        self::assertSame([], $settings->supplementPacks);
    }

    #[Test]
    public function un_pack_achete_emit_sa_facture_et_n_accorde_rien_encore(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);
        $this->subscribe($projectId);
        $this->declarePacks($projectId, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 50,
            'unit_price_minor' => 5_000,
            'label' => 'Pack de 50 messages',
        ]]);

        $purchase = app(PurchaseSubscriptionSupplementPack::class)->purchase(
            new PurchaseSubscriptionSupplementPackCommand(
                projectId: $projectId,
                subscriptionId: $this->subscriptionId($projectId),
                packCode: 'pack-50',
                idempotencyKey: 'portal-pack:abc',
                actor: 'portal:test',
            ),
        );

        // Le pack : sa quantité et son prix viennent du catalogue, jamais
        // du client.
        self::assertSame(50, $purchase->supplement->quantity);
        self::assertSame(5_000, $purchase->supplement->unitPriceMinor);
        self::assertSame(250_000, $purchase->invoice->totalMinor);
        self::assertSame('pending', $purchase->supplement->status);
        self::assertSame('open', $purchase->invoice->status);

        // La commande seule n'accorde rien : la limite n'a pas bougé.
        self::assertSame(100, $this->limit($projectId));
    }

    #[Test]
    public function un_rejeu_de_la_meme_cle_achete_une_seule_fois(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);
        $this->subscribe($projectId);
        $this->declarePacks($projectId, [[
            'code' => 'pack-50',
            'meter' => self::METER,
            'quantity' => 50,
            'unit_price_minor' => 5_000,
            'label' => 'Pack de 50 messages',
        ]]);

        $command = new PurchaseSubscriptionSupplementPackCommand(
            projectId: $projectId,
            subscriptionId: $this->subscriptionId($projectId),
            packCode: 'pack-50',
            idempotencyKey: 'portal-pack:abc',
            actor: 'portal:test',
        );

        $first = app(PurchaseSubscriptionSupplementPack::class)->purchase($command);
        $second = app(PurchaseSubscriptionSupplementPack::class)->purchase($command);

        // Le rejeu rend le **même** résultat : le même supplément, la
        // même facture.
        self::assertSame($first->supplement->id, $second->supplement->id);
        self::assertSame($first->invoice->id, $second->invoice->id);

        self::assertSame(1, DB::table('subscription_supplements')->count());
        self::assertSame(1, DB::table('event_outbox')->where('type', 'subscription.supplement_purchased')->count());
    }

    /**
     * ADR-0121 — une clé d'idempotence porte **un** geste. La réutiliser
     * pour un autre pack ne rejoue pas l'achat : elle est refusée, sinon
     * l'appelant recevrait le résultat d'un pack qu'il n'a pas choisi.
     */
    #[Test]
    public function une_cle_qui_porte_deja_un_autre_achat_est_refusee(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);
        $this->subscribe($projectId);
        $this->declarePacks($projectId, [
            [
                'code' => 'pack-50',
                'meter' => self::METER,
                'quantity' => 50,
                'unit_price_minor' => 5_000,
                'label' => 'Pack de 50 messages',
            ],
            [
                'code' => 'pack-100',
                'meter' => self::METER,
                'quantity' => 100,
                'unit_price_minor' => 5_000,
                'label' => 'Pack de 100 messages',
            ],
        ]);

        $subscriptionId = $this->subscriptionId($projectId);

        app(PurchaseSubscriptionSupplementPack::class)->purchase(new PurchaseSubscriptionSupplementPackCommand(
            projectId: $projectId,
            subscriptionId: $subscriptionId,
            packCode: 'pack-50',
            idempotencyKey: 'console-pack:une-seule-cle',
            actor: 'portal:test',
        ));

        $this->expectException(InvalidSubscriptionSupplement::class);

        app(PurchaseSubscriptionSupplementPack::class)->purchase(new PurchaseSubscriptionSupplementPackCommand(
            projectId: $projectId,
            subscriptionId: $subscriptionId,
            packCode: 'pack-100',
            idempotencyKey: 'console-pack:une-seule-cle',
            actor: 'portal:test',
        ));
    }

    #[Test]
    public function un_pack_retire_du_catalogue_ne_sachete_plus(): void
    {
        $projectId = $this->project();
        $this->plan($projectId);
        $this->subscribe($projectId);

        $this->expectException(InvalidSubscriptionSupplement::class);

        app(PurchaseSubscriptionSupplementPack::class)->purchase(new PurchaseSubscriptionSupplementPackCommand(
            projectId: $projectId,
            subscriptionId: $this->subscriptionId($projectId),
            packCode: 'pack-50',
            idempotencyKey: 'portal-pack:abc',
            actor: 'portal:test',
        ));
    }

    // ------------------------------------------------------------------ helpers

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Projet'), actor: 'service:test', correlationId: null)->project->id;
    }

    /**
     * Une offre publiée qui porte un compteur : c'est le compteur que le
     * projet compte, donc le seul qu'un pack peut étendre.
     */
    private function plan(string $projectId): void
    {
        app(CreatePlan::class)->create(new CreatePlanCommand(
            projectId: $projectId,
            code: 'pro',
            name: 'Pro',
            description: null,
            visibility: 'public',
            sortOrder: 0,
            actor: 'test:pack',
            correlationId: null,
        ));

        $version = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand(
            projectId: $projectId,
            planCode: 'pro',
            priceMinor: 25_000,
            currency: 'XAF',
            intervalUnit: 'month',
            intervalCount: 1,
            trialDays: 0,
            graceDays: 7,
            graceAccess: true,
            features: [
                'messages' => ['type' => 'limit', 'meter' => self::METER, 'limit' => 100, 'reset' => 'period', 'overage' => 'block'],
            ],
            actor: 'test:pack',
            correlationId: null,
        ));

        app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand(
            projectId: $projectId,
            planCode: 'pro',
            version: $version->version,
            actor: 'test:pack',
            correlationId: null,
        ));
    }

    private function subscribe(string $projectId): void
    {
        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: $projectId,
            externalRef: 'acme',
            displayName: 'ACME',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:pack',
            correlationId: null,
        ));

        app(CreateSubscription::class)->create(new CreateSubscriptionCommand(
            projectId: $projectId,
            customerRef: 'acme',
            planCode: 'pro',
            // Un essai : le contrat porte un droit, donc il peut
            // recevoir un supplément (ADR-0117 §2).
            trialDays: 14,
            startAt: null,
            idempotencyKey: 'sub-acme',
            actor: 'test:pack',
            correlationId: null,
        ));
    }

    private function subscriptionId(string $projectId): string
    {
        $id = DB::table('subscriptions')->where('project_id', $projectId)->value('id');

        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<array{code: string, meter: string, quantity: int, unit_price_minor: int, label: string}> $packs
     */
    private function declarePacks(string $projectId, array $packs): void
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
            actor: 'test:pack',
            supplementPacks: $packs,
        ), AccessScope::platform());
    }

    private function limit(string $projectId): ?int
    {
        $view = app(GetEntitlements::class)->get(
            new GetEntitlementsCommand($projectId, 'acme'),
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
}
