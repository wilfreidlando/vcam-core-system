<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * G15 — the generated IDOR test (ADR-0081 S-01/S-04, ADR-0069 §Sécurité).
 *
 * The account under test has no platform role and is `org_owner` of
 * organization A (every organization permission, resolved by the real
 * `ResolveAccessScope`). For every manifest entry:
 *
 *  - **isolation** — B's resource answers the declared "not found"
 *    (never 403), the response — body, redirect target, flashed messages —
 *    carries none of B's data, B's rows are unchanged and no provider was
 *    called; for nested routes, also with B's child under A's parent;
 *  - **positive control** (`control: true`) — the same request on A's
 *    equivalent resource is answered, so a route that 404s everything
 *    cannot pass;
 *  - **platform reservation** — refused with 403 for B's identifier and for
 *    an identifier that does not exist alike: the refusal confirms nothing.
 *
 * Rows are written inside a transaction rolled back after each case: the
 * test database is shared, never refreshed by hand.
 */
#[Group('arch')]
final class ConsoleIdorTest extends TestCase
{
    private IdorScenarioFactory $scenario;

    /**
     * @return array<string, array{IdorRouteEntry, bool}>
     */
    public static function isolationCases(): array
    {
        $cases = [];

        foreach (ConsoleRouteManifest::withCoverage(IdorCoverage::Isolated) as $entry) {
            $cases[$entry->route] = [$entry, false];

            if ($entry->ownParent !== null) {
                $cases[$entry->route.' [B\'s resource under A\'s parent]'] = [$entry, true];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{IdorRouteEntry}>
     */
    public static function controlCases(): array
    {
        $cases = [];

        foreach (ConsoleRouteManifest::entries() as $entry) {
            if ($entry->control) {
                $cases[$entry->route] = [$entry];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{IdorRouteEntry}>
     */
    public static function platformReservedCases(): array
    {
        $cases = [];

        foreach (ConsoleRouteManifest::withCoverage(IdorCoverage::PlatformReserved) as $entry) {
            $cases[$entry->route] = [$entry];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('isolationCases')]
    public function an_organization_owner_of_a_gets_nothing_of_organization_b(IdorRouteEntry $entry, bool $underOwnParent): void
    {
        $b = $this->scenario->b;
        $resource = $this->scenario->request($entry->resource, $b, $underOwnParent ? $this->scenario->a : $b);
        $expected = $underOwnParent ? $entry->ownParent : $entry->expected;
        self::assertNotNull($expected);

        $label = $entry->describe().($underOwnParent ? ' with B\'s resource under A\'s parent' : '');
        $before = $this->scenario->footprint($b);

        [$uri, $body] = $this->target($entry, $resource);
        $response = $this->send($entry, $uri, $body);
        $status = $response->baseResponse->getStatusCode();

        self::assertNotSame(403, $status, sprintf(
            '%s answered 403 for a resource of another organization: a 403 confirms the resource exists — '
            .'it must answer "not found" (ADR-0081 S-04, ADR-0069 §2).',
            $label,
        ));

        if ($expected === ExpectedOutcome::NotFound) {
            self::assertSame(404, $status, sprintf('%s answered %d for a resource of another organization; it must answer 404 (ADR-0081 S-04).', $label, $status));
        } else {
            self::assertTrue($response->baseResponse->isRedirect(), sprintf('%s answered %d; it must answer the console\'s "introuvable" redirect.', $label, $status));
            self::assertNotSame([], $this->flashedErrors(), sprintf('%s redirected without an "introuvable" error: it reads as a success.', $label));
            self::assertNull(session('status'), sprintf('%s flashed a success message for a resource of another organization.', $label));
        }

        $haystack = implode("\n", [
            (string) $response->baseResponse->getContent(),
            (string) $response->baseResponse->headers->get('Location'),
            ...$this->flashedErrors(),
        ]);
        $sent = $uri.' '.json_encode($body, JSON_THROW_ON_ERROR);

        foreach ([...$b->markers(), ...$resource->markers] as $marker) {
            // What the caller sent is no secret to it (the identifiers it typed).
            if (str_contains($sent, $marker)) {
                continue;
            }

            self::assertStringNotContainsString($marker, $haystack, sprintf(
                '%s returned data of another organization ("%s") (ADR-0081 S-01).',
                $label,
                $marker,
            ));
        }

        self::assertSame($before, $this->scenario->footprint($b), sprintf(
            '%s changed the rows of another organization while answering "not found": still an IDOR (ADR-0081 S-01).',
            $label,
        ));

        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('controlCases')]
    public function the_same_account_reaches_the_equivalent_resource_of_its_own_organization(IdorRouteEntry $entry): void
    {
        $resource = $this->scenario->request($entry->resource, $this->scenario->a);

        [$uri, $body] = $this->target($entry, $resource);
        $response = $this->send($entry, $uri, $body);
        $status = $response->baseResponse->getStatusCode();

        $answered = $this->methodOf($this->route($entry)) === 'GET'
            ? $status === 200
            : ($status >= 200 && $status < 300) || ($response->baseResponse->isRedirect() && $this->flashedErrors() === []);

        self::assertTrue($answered, sprintf(
            'Positive control: %s answered %d%s on the account\'s own organization. A route that refuses its own '
            .'tenant too would pass the IDOR case without proving any isolation.',
            $entry->describe(),
            $status,
            $this->flashedErrors() === [] ? '' : ' with errors: '.implode(' / ', $this->flashedErrors()),
        ));
    }

    #[Test]
    #[DataProvider('platformReservedCases')]
    public function a_platform_reserved_route_refuses_an_organization_account_whatever_the_identifier(IdorRouteEntry $entry): void
    {
        $resource = $this->scenario->request($entry->resource, $this->scenario->b);
        $missing = new ResourceRequest(array_map(
            static fn (string $value): string => Str::isUuid($value) ? (string) Str::uuid() : 'missing-'.Str::lower(Str::random(10)),
            $resource->parameters,
        ));

        foreach (['an existing identifier' => $resource, 'an identifier that does not exist' => $missing] as $case => $request) {
            [$uri, $body] = $this->target($entry, $request);
            $response = $this->send($entry, $uri, $body);

            self::assertSame(403, $response->baseResponse->getStatusCode(), sprintf(
                '%s answered %d to an organization account for %s: the route is declared platform-reserved — '
                .'refused before any lookup. If it is now open to organizations, make it an isolated entry.',
                $entry->describe(),
                $response->baseResponse->getStatusCode(),
                $case,
            ));
        }

        Http::assertNothingSent();
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'no provider in the IDOR guard'], 503)]);
        Queue::fake();

        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->scenario = IdorScenarioFactory::create();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    private function route(IdorRouteEntry $entry): Route
    {
        $route = RouteFacade::getRoutes()->getByName($entry->route);
        self::assertInstanceOf(Route::class, $route, sprintf('The route "%s" is not registered any more.', $entry->route));

        return $route;
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function target(IdorRouteEntry $entry, ResourceRequest $resource): array
    {
        $route = $this->route($entry);
        $uri = '/'.ltrim($route->uri(), '/');

        foreach ($route->parameterNames() as $parameter) {
            $name = is_string($parameter) ? $parameter : '';
            self::assertArrayHasKey($name, $resource->parameters, sprintf(
                'The scenario for "%s" provides no value for the "%s" parameter of %s.',
                $entry->resource->value,
                $name,
                $entry->route,
            ));
            $uri = str_replace('{'.$name.'}', $resource->parameters[$name], $uri);
        }

        if ($resource->query !== []) {
            $uri .= '?'.http_build_query($resource->query);
        }

        return [$uri, self::bodyFor($entry->route)];
    }

    /**
     * @param array<string, mixed> $body
     * @return TestResponse<Response>
     */
    private function send(IdorRouteEntry $entry, string $uri, array $body): TestResponse
    {
        $route = $this->route($entry);
        $method = $this->methodOf($route);
        $token = $this->scenario->attackerToken;

        if (ConsoleRouteManifest::planeOf($route) === ConsolePlane::Web) {
            $this->withSession([ResolveOperatorMiddleware::SESSION_KEY => $token]);

            return $this->call($method, $uri, $body);
        }

        return $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$token]);
    }

    private function methodOf(Route $route): string
    {
        foreach ($route->methods() as $method) {
            if (is_string($method) && $method !== 'HEAD') {
                return $method;
            }
        }

        return 'GET';
    }

    /**
     * @return list<string>
     */
    private function flashedErrors(): array
    {
        $errors = session('errors');

        // The session is JSON-serialized in this application: the error bag
        // comes back as its array form, not as a `ViewErrorBag`.
        $messages = $errors instanceof ViewErrorBag ? $errors->getBag('default')->all() : Arr::flatten((array) data_get($errors, 'default.messages', []));

        return array_values(array_filter($messages, is_string(...)));
    }

    /**
     * A body valid for the route: a request the validator rejects would
     * answer 302 and pass for the console's "not found" redirect.
     *
     * @return array<string, mixed>
     */
    private static function bodyFor(string $route): array
    {
        return match ($route) {
            'admin.organizations.update' => ['name' => 'Renommée par IDOR', 'status' => 'active'],
            'admin.organizations.members.store', 'admin.organizations.invitations.create' => ['email' => 'idor-invitee@example.com', 'role' => 'org_viewer'],
            'admin.organizations.members.role.web', 'admin.organizations.members.update' => ['role' => 'org_finance'],
            'admin.projects.update' => ['name' => 'Renommé par IDOR', 'status' => 'active', 'reason' => 'Test IDOR'],
            'admin.projects.api-keys.store' => ['name' => 'Clé IDOR', 'template' => 'payment'],
            'admin.projects.api-keys.rotate' => ['grace_days' => 7],
            'admin.projects.api-keys.revoke' => ['reason' => 'Révocation IDOR'],
            'admin.projects.provider-applications.store' => ['provider' => 'my-coolpay', 'public_key' => 'pub-idor', 'private_key' => 'private-idor'],
            'admin.projects.webhook-endpoints.store', 'admin.projects.webhook-endpoints.update' => ['url' => 'https://example.com/idor-hook', 'categories' => ['payments']],
            'admin.projects.webhook-endpoints.rotate-secret' => ['overlap_hours' => 24],
            'admin.projects.webhook-endpoints.revoke-previous-secret' => ['reason' => 'Révocation IDOR'],
            'admin.projects.channel-sessions.store' => ['session_ref' => 'session-idor'],
            'admin.projects.channel-sessions.pairing-code', 'admin.channel-sessions.pairing-code' => ['phone_number' => '+237600000000'],
            'admin.money-exceptions.resolve' => ['resolution' => 'no_action', 'note' => 'Note de test IDOR'],
            'admin.operators.update', 'admin.operators.update.web' => ['email' => 'idor-target@example.com', 'name' => 'Cible', 'role' => 'platform_admin'],
            // The commerce plane (ADR-0070 / ADR-0071 / ADR-0072). The
            // body matters: without it the request would be refused by
            // the **validation** and the case would prove nothing about
            // the isolation — the gesture has to reach the use case.
            'admin.projects.customers.update.web' => ['external_ref' => 'idor-client', 'display_name' => 'Renommé par IDOR'],
            'admin.projects.customers.erase.web' => ['reason' => 'Effacement IDOR'],
            'admin.projects.plans.versions.store' => [
                'price_minor' => 5000, 'currency' => 'XAF', 'interval_unit' => 'month', 'interval_count' => 1,
            ],
            'admin.projects.subscriptions.pause.web' => ['reason' => 'Pause IDOR'],
            'admin.projects.subscriptions.cancel.web' => ['at_period_end' => true, 'reason' => 'Annulation IDOR'],
            'admin.projects.subscriptions.change-plan.web' => ['plan_code' => 'idor-plan', 'effective' => 'period_end'],
            'admin.projects.subscriptions.complimentary.web' => ['days' => 30, 'reason' => 'Offre IDOR'],
            'admin.projects.subscriptions.pay.web' => ['seq' => 1, 'reference' => 'virement-idor'],
            default => [],
        };
    }
}
