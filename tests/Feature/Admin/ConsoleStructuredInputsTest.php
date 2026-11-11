<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\ListApiKeys;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Support\Access\AccessScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SignsInToConsole;
use Tests\TestCase;

/**
 * La console ne prend plus de texte à parser : une ligne par élément, un
 * bloc de champs par objet.
 *
 * Cinq écrans, un test chacun : ce que l'opérateur voit, ce que sa saisie
 * produit (identique à ce que produisait l'ancienne forme), et le refus
 * d'une saisie incomplète.
 */
final class ConsoleStructuredInputsTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToConsole;

    #[Test]
    public function the_features_of_a_version_are_declared_block_by_block(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        // Le rendu porte des champs, plus une zone de texte à parser.
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = $this->get("/admin/projects/{$projectId}/plans/create")->assertOk()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('name="features[0][name]"', $html);
        self::assertStringContainsString('name="features[0][type]"', $html);
        self::assertStringContainsString('name="features[0][meter]"', $html);
        self::assertStringContainsString('name="features[0][limit]"', $html);
        self::assertStringContainsString('name="features[0][reset]"', $html);
        self::assertStringContainsString('name="features[0][overage]"', $html);
        self::assertStringNotContainsString('name="features"', $html);
        self::assertStringNotContainsString('JSON', $html);

        $this->post("/admin/projects/{$projectId}/plans", ['code' => 'pro', 'name' => 'Pro', 'visibility' => 'public'])
            ->assertRedirect("/admin/projects/{$projectId}/plans");

        // Une saisie complète : deux blocs, un interrupteur et une limite.
        $this->post("/admin/projects/{$projectId}/plans/versions", [
            'plan_code' => 'pro', 'price_minor' => '25000', 'currency' => 'XAF', 'interval_unit' => 'month', 'interval_count' => '1',
            'trial_days' => '0', 'grace_days' => '0',
            'features' => [
                ['name' => 'api_access', 'type' => 'boolean', 'enabled' => '1', 'meter' => '', 'limit' => '', 'reset' => '', 'overage' => '', 'threshold_bps' => ''],
                ['name' => 'students', 'type' => 'limit', 'enabled' => '', 'meter' => 'students', 'limit' => '10', 'reset' => 'never', 'overage' => 'block', 'threshold_bps' => ''],
            ],
        ])->assertRedirect("/admin/projects/{$projectId}/plans");

        // Le résultat en base est EXACTEMENT ce que donnait l'ancien JSON.
        $stored = $this->stored('plan_versions', $projectId, 'features');
        self::assertSame(
            $this->canonical(json_decode('{"api_access":{"type":"boolean","enabled":true},"students":{"type":"limit","meter":"students","limit":10,"reset":"never","overage":"block"}}', true)),
            $this->canonical($stored),
        );

        // Un bloc incomplet est refusé avec une phrase, et rien n'est écrit.
        $this->post("/admin/projects/{$projectId}/plans/versions", [
            'plan_code' => 'pro', 'price_minor' => '25000', 'currency' => 'XAF', 'interval_unit' => 'month', 'interval_count' => '1',
            'features' => [
                ['name' => 'students', 'type' => '', 'enabled' => '', 'meter' => '', 'limit' => '', 'reset' => '', 'overage' => '', 'threshold_bps' => ''],
            ],
        ])->assertSessionHasErrors('features.0.type');

        self::assertSame(1, DB::table('plan_versions')->where('project_id', $projectId)->count());
    }

    #[Test]
    public function the_meter_mappings_are_declared_block_by_block(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = $this->get("/admin/projects/{$projectId}/commerce-settings")->assertOk()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('name="meter_mappings[0][capability]"', $html);
        self::assertStringContainsString('name="meter_mappings[0][meter]"', $html);
        self::assertStringNotContainsString('name="meter_mappings"', $html);

        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => [
                ['capability' => 'notification.whatsapp', 'meter' => 'whatsapp_messages'],
            ],
        ]))->assertRedirect("/admin/projects/{$projectId}/commerce-settings");

        // Le résultat en base est EXACTEMENT ce que donnait l'ancienne ligne.
        $stored = $this->stored('billing_settings', $projectId, 'meter_mappings');
        self::assertSame($this->canonical(json_decode('{"notification.whatsapp":"whatsapp_messages"}', true)), $this->canonical($stored));

        // La console montre l'état courant : les deux cases sont remplies.
        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $again = $this->get("/admin/projects/{$projectId}/commerce-settings")->assertOk()->getContent();
        self::assertIsString($again);
        self::assertStringContainsString('value="notification.whatsapp"', $again);
        self::assertStringContainsString('value="whatsapp_messages"', $again);

        // Une correspondance que le projet n'a pas le droit de déclarer est refusée.
        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => [
                ['capability' => 'Bad Capability', 'meter' => 'whatsapp_messages'],
            ],
        ]))->assertSessionHasErrors('resource');

        self::assertSame(
            $this->canonical(json_decode('{"notification.whatsapp":"whatsapp_messages"}', true)),
            $this->canonical($this->stored('billing_settings', $projectId, 'meter_mappings')),
        );
    }

    #[Test]
    public function the_supplement_packs_are_declared_block_by_block(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = $this->get("/admin/projects/{$projectId}/commerce-settings")->assertOk()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('name="supplement_packs[0][code]"', $html);
        self::assertStringContainsString('name="supplement_packs[0][meter]"', $html);
        self::assertStringContainsString('name="supplement_packs[0][quantity]"', $html);
        self::assertStringContainsString('name="supplement_packs[0][unit_price_minor]"', $html);
        self::assertStringContainsString('name="supplement_packs[0][label]"', $html);
        self::assertStringNotContainsString('name="supplement_packs"', $html);

        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => [
                ['capability' => 'notification.whatsapp', 'meter' => 'whatsapp_messages'],
            ],
            'supplement_packs' => [
                ['code' => 'pack50', 'meter' => 'whatsapp_messages', 'quantity' => '50', 'unit_price_minor' => '5000', 'label' => 'Pack de 50 messages WhatsApp'],
            ],
        ]))->assertRedirect("/admin/projects/{$projectId}/commerce-settings");

        // Le résultat en base est EXACTEMENT ce que donnait l'ancienne ligne.
        $stored = $this->stored('billing_settings', $projectId, 'supplement_packs');
        self::assertSame(
            $this->canonical(json_decode('[{"code":"pack50","meter":"whatsapp_messages","quantity":50,"unit_price_minor":5000,"label":"Pack de 50 messages WhatsApp"}]', true)),
            $this->canonical($stored),
        );

        // Un pack incomplet est refusé avec une phrase, et le catalogue reste.
        $this->post("/admin/projects/{$projectId}/commerce-settings", $this->settings([
            'meter_mappings' => [
                ['capability' => 'notification.whatsapp', 'meter' => 'whatsapp_messages'],
            ],
            'supplement_packs' => [
                ['code' => 'pack50', 'meter' => 'whatsapp_messages', 'quantity' => '50', 'unit_price_minor' => '5000', 'label' => ''],
            ],
        ]))->assertSessionHasErrors('resource');

        self::assertSame(
            $this->canonical(json_decode('[{"code":"pack50","meter":"whatsapp_messages","quantity":50,"unit_price_minor":5000,"label":"Pack de 50 messages WhatsApp"}]', true)),
            $this->canonical($this->stored('billing_settings', $projectId, 'supplement_packs')),
        );
    }

    #[Test]
    public function the_network_restriction_of_a_key_is_one_address_per_line(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = $this->get("/admin/projects/{$projectId}/api-keys/create")->assertOk()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('name="allowed_cidrs"', $html);
        self::assertStringContainsString('Une adresse par ligne', $html);

        $this->post("/admin/projects/{$projectId}/api-keys", [
            'name' => 'Versements', 'template' => 'payouts',
            'allowed_cidrs' => "203.0.113.0/24\n2001:db8::/32",
        ])->assertOk();

        $keys = app(ListApiKeys::class)->list(new ListApiKeysCommand($projectId), AccessScope::platform());
        $created = collect($keys)->firstWhere('name', 'Versements');
        self::assertNotNull($created);
        self::assertSame(['203.0.113.0/24', '2001:db8::/32'], $created->allowedCidrs);

        // Une adresse que le réseau n'a pas est refusée avec une phrase.
        $this->post("/admin/projects/{$projectId}/api-keys", [
            'name' => 'Réseau', 'template' => 'server', 'allowed_cidrs' => '10.0.0.0/33',
        ])->assertSessionHasErrors('name');

        self::assertCount(2, app(ListApiKeys::class)->list(new ListApiKeysCommand($projectId), AccessScope::platform()));
    }

    #[Test]
    public function the_return_urls_of_the_portal_are_one_per_line(): void
    {
        $projectId = $this->project();
        $this->signInToConsole();

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $html = $this->get("/admin/projects/{$projectId}/portal")->assertOk()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('name="allowed_return_urls"', $html);
        self::assertStringContainsString('Une URL par ligne', $html);

        $this->post("/admin/projects/{$projectId}/portal", [
            'display_name' => 'Mon Auto-École',
            'allowed_return_urls' => "https://app.mon-saas.cm/compte\nhttps://admin.mon-saas.cm",
        ])->assertRedirect("/admin/projects/{$projectId}/portal");

        self::assertSame(
            ['https://app.mon-saas.cm/compte', 'https://admin.mon-saas.cm'],
            $this->stored('portal_settings', $projectId, 'allowed_return_urls'),
        );

        // Une adresse de retour qui n'est pas en https est refusée avec une phrase.
        $this->post("/admin/projects/{$projectId}/portal", [
            'display_name' => 'Mon Auto-École',
            'allowed_return_urls' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('resource');

        self::assertSame(
            ['https://app.mon-saas.cm/compte', 'https://admin.mon-saas.cm'],
            $this->stored('portal_settings', $projectId, 'allowed_return_urls'),
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A jsonb column of the project, read back.
     */
    private function stored(string $table, string $projectId, string $column): mixed
    {
        $raw = DB::table($table)->where('project_id', $projectId)->value($column);
        self::assertIsString($raw);

        return json_decode($raw, true);
    }

    /**
     * A structure compared to another, key order aside: PostgreSQL's jsonb
     * reorders the keys it stores, and only the content matters.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonical($item);
        }

        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * The settings of the project, as the form posts them.
     *
     * @param array<string, mixed> $plus
     * @return array<string, mixed>
     */
    private function settings(array $plus = []): array
    {
        return array_merge([
            'timezone' => 'Africa/Douala',
            'default_currency' => 'XAF',
            'renewal_lead_days' => '7',
            'incomplete_expiry_hours' => '72',
            'invoice_prefix' => 'FACT',
            'default_tax_rate_percent' => '0',
            'manual_receipt_threshold' => '0',
            'meter_mappings' => [],
            'supplement_packs' => [],
            'refund_failed_usage' => '1',
        ], $plus);
    }

    private function project(): string
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Org'), actor: 'service:test', correlationId: null);

        return app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Mon Auto-École'), actor: 'service:test', correlationId: null)->project->id;
    }
}
