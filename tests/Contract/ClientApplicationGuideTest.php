<?php

declare(strict_types=1);

namespace Tests\Contract;

use Core\Identity\Contracts\ApiKeyScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * The guide of the client application must not lie.
 *
 * `docs/integration/brancher-une-application-cliente.md` is what a developer
 * reads before writing the first line of the integration. A command that
 * names an endpoint that no longer exists, or whose documented answer is
 * wrong, sends them down a dead end for a day.
 *
 * This test executes the guide's own calls against the Core and confronts
 * every path it names with the routing table.
 */
final class ClientApplicationGuideTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    private const GUIDE = __DIR__.'/../../docs/integration/brancher-une-application-cliente.md';

    #[Test]
    public function every_endpoint_the_guide_names_is_routed(): void
    {
        $paths = $this->guidePaths();

        self::assertNotEmpty($paths, 'no path was found in the guide — the extraction is broken');

        $routed = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routed[] = self::normalise($route->uri());
        }

        foreach ($paths as $path) {
            self::assertContains(
                $path,
                $routed,
                sprintf('The guide names `%s`, which the Core no longer routes.', $path),
            );
        }
    }

    #[Test]
    public function the_guide_calls_answer_what_it_promises(): void
    {
        $project = $this->makeProject('Guide');
        $server = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_SERVER), 'Server');
        $client = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP), 'Client app');

        // §1 — « Vérifiez-la »
        $this->getJson('/v1/whoami', $client)->assertOk();

        // §2 — « 201 si le locataire est créé, 200 s'il existe déjà »
        $this->putJson('/v1/customers/auto-ecole-42', ['display_name' => 'Auto-École La Fontaine'], $server)->assertCreated();
        $this->putJson('/v1/customers/auto-ecole-42', ['display_name' => 'Auto-École La Fontaine'], $server)->assertOk();

        // §3 — « 200 », avec les droits
        $rights = $this->getJson('/v1/customers/auto-ecole-42/entitlements', $client);
        $rights->assertOk();
        $body = self::map($rights->json());
        self::assertArrayHasKey('active', $body);
        self::assertArrayHasKey('entitlements', $body);

        // §4 — « 201 (compté), 200 (rejeu — déjà compté) »
        $usage = ['customer_ref' => 'auto-ecole-42', 'meter' => 'students', 'quantity' => 1, 'idempotency_key' => 'inscription:STUDENT-2026-0042'];
        $this->postJson('/v1/usage', $usage, $client)->assertCreated();
        $this->postJson('/v1/usage', $usage, $client)->assertOk();

        // §5 — « 201 », avec un lien à usage unique
        $portal = $this->postJson('/v1/portal/sessions', ['customer_ref' => 'auto-ecole-42', 'flow' => 'manage'], $client);
        $portal->assertCreated();
        self::assertStringContainsString('/portal/enter/', self::text($portal->json('url')));
    }

    #[Test]
    public function the_guide_does_not_ask_the_client_application_to_do_commerce(): void
    {
        // The guide tells the client application to open the portal without
        // `customer` nor `plan_code`: those would need a write scope it does
        // not have (ADR-0107 §5). The documented call must stay that way.
        $guide = (string) file_get_contents(self::GUIDE);

        self::assertStringContainsString('Ne transmettez pas `customer` ni `plan_code`', $guide);

        $project = $this->makeProject('Guide');
        $client = $this->keyFor($project['project_id'], ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP), 'Client app');

        $this->postJson('/v1/portal/sessions', [
            'customer_ref' => 'auto-ecole-42',
            'customer' => ['display_name' => 'ACME'],
        ], $client)->assertForbidden();
    }

    /**
     * Every `/v1/…` path the guide names, normalised.
     *
     * @return list<string>
     */
    private function guidePaths(): array
    {
        $guide = (string) file_get_contents(self::GUIDE);

        preg_match_all('#/v1/[A-Za-z0-9\-_{}$./]+#', $guide, $matches);

        $paths = [];
        foreach ($matches[0] as $path) {
            $path = rtrim($path, '/.');

            // A path that carries a variable is a path template.
            $path = (string) preg_replace('/\{[^}]*\}/', '{}', $path);
            $path = (string) preg_replace('/\$[A-Z_]+/', '{}', $path);

            $paths[ltrim($path, '/')] = true;
        }

        $list = array_keys($paths);
        sort($list);

        return $list;
    }

    /**
     * A route's URI, with its parameters reduced to `{}` — the shape a
     * guide uses when it writes `{ref}`.
     */
    private static function normalise(string $uri): string
    {
        return ltrim((string) preg_replace('/\{[^}]*\}/', '{}', $uri), '/');
    }
}
