<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Billing\Contracts\PayInvoice;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Portal\Application\Dto\OpenPortalSessionCommand;
use Core\Portal\Application\UseCase\OpenPortalSessionUseCase;
use Core\Portal\Domain\Exception\InvalidPortalFlow;
use Core\Portal\Domain\Model\PortalSession;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * G-10 — the generated IDOR test of the portal (audit 2026-10-29 #20,
 * ADR-0081 S-01/S-04, ADR-0075 §3).
 *
 * The customer under test and her neighbour are **two customers of the
 * same project**: the customer is the tenant of the portal, and that is
 * the boundary a forgotten `customerId` check would break — the console's
 * project boundary cannot catch it.
 *
 * For every manifest entry:
 *
 *  - **isolation** — the neighbour's resource answers the declared "not
 *    found" (never 403), the answer is the one of a resource that does
 *    not exist (same status, same page — a difference would confirm that
 *    the resource exists), it carries none of the neighbour's data, her
 *    rows are unchanged and no payment was even started;
 *  - **positive control** (`control: true`) — the same request on the
 *    customer's own resource is answered and changes what it should
 *    change, so a route that refuses everybody cannot pass;
 *  - **public door** — a project that does not exist and a project of
 *    another tenant answer exactly the same thing;
 *  - **one-time link** — the access link works once, expires, and opens
 *    the session of its own project and its own customer.
 *
 * Rows are written inside a transaction rolled back after each case: the
 * test database is shared, never refreshed by hand.
 */
#[Group('arch')]
final class PortalIdorTest extends TestCase
{
    /** A nonce the portal accepts: the form's anti-replay token. */
    private const NONCE = 'abababababababababababababababab';

    private PortalPaymentSpy $payments;

    /**
     * @return array<string, array{PortalRouteEntry}>
     */
    public static function isolationCases(): array
    {
        $cases = [];

        foreach (PortalRouteManifest::withCoverage(PortalCoverage::Isolated) as $entry) {
            $cases[IdorSector::label($entry->route)] = [$entry];
        }

        return $cases;
    }

    /**
     * @return array<string, array{PortalRouteEntry}>
     */
    public static function controlCases(): array
    {
        $cases = [];

        foreach (PortalRouteManifest::withCoverage(PortalCoverage::Isolated) as $entry) {
            if ($entry->control) {
                $cases[IdorSector::label($entry->route)] = [$entry];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{PortalRouteEntry}>
     */
    public static function publicDoorCases(): array
    {
        $cases = [];

        foreach (PortalRouteManifest::withCoverage(PortalCoverage::PublicDoor) as $entry) {
            $cases[IdorSector::label($entry->route)] = [$entry];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('isolationCases')]
    public function a_customer_gets_nothing_of_the_neighbour_of_the_same_project(PortalRouteEntry $entry): void
    {
        $scenario = PortalScenarioFactory::create($entry->route);

        // An offer belongs to a **project**, not to a customer: the foreign
        // offer is the one of another project — the boundary above the
        // customer. Every other resource belongs to the neighbour of the
        // same project, which is the boundary the `customerId` check holds.
        $neighbour = $entry->resource === PortalResourceNature::Plan ? $scenario->other : $scenario->b;

        $resource = $scenario->request($entry->resource, $neighbour);
        $expected = $entry->expected;
        self::assertNotNull($expected);

        $label = $entry->describe();
        $before = $scenario->footprint($neighbour);

        [$uri, $body] = $this->target($entry, $resource, $scenario);
        $response = $this->send($entry, $uri, $body, $scenario);
        $status = $response->baseResponse->getStatusCode();

        self::assertNotSame(403, $status, sprintf(
            '%s answered 403 for a resource of another customer: a 403 confirms the resource exists — '
            .'it must answer "not found" (ADR-0081 S-04, ADR-0075 §3).',
            $label,
        ));

        self::assertSame($expected->status(), $status, sprintf(
            '%s answered %d for a resource of another customer; it must answer %d (ADR-0081 S-04).',
            $label,
            $status,
            $expected->status(),
        ));

        // The answer must be the one of a resource that does not exist: the
        // same status, the same page. A difference would confirm that the
        // resource exists somewhere.
        [$missingUri, $missingBody] = $this->target($entry, $this->missingResource($entry), $scenario);
        $missingResponse = $this->send($entry, $missingUri, $missingBody, $scenario);

        self::assertSame($missingResponse->baseResponse->getStatusCode(), $status, sprintf(
            '%s answers %d for a resource of another customer but %d for an identifier nobody ever had: '
            .'the difference confirms that the resource exists (ADR-0081 S-04).',
            $label,
            $status,
            $missingResponse->baseResponse->getStatusCode(),
        ));

        self::assertSame($this->html($missingResponse), $this->html($response), sprintf(
            '%s answers a different page for a resource of another customer: the page confirms its existence.',
            $label,
        ));

        // Not a byte of the neighbour in the answer.
        $haystack = (string) $response->baseResponse->getContent();
        $sent = $uri.' '.json_encode($body, JSON_THROW_ON_ERROR);

        foreach ($neighbour->markers() as $marker) {
            // What the caller sent is no secret to it (the identifiers it typed).
            if (str_contains($sent, $marker)) {
                continue;
            }

            self::assertStringNotContainsString($marker, $haystack, sprintf(
                '%s returned data of another customer ("%s") (ADR-0081 S-01).',
                $label,
                $marker,
            ));
        }

        // And nothing of the neighbour changed either.
        self::assertSame($before, $scenario->footprint($neighbour), sprintf(
            '%s changed the rows of another customer while answering "not found": still an IDOR (ADR-0081 S-01).',
            $label,
        ));

        // The portal did not even try to collect the neighbour's invoice.
        self::assertSame([], $this->payments->commands(), sprintf(
            '%s started a payment on a resource of another customer.',
            $label,
        ));

        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('controlCases')]
    public function the_same_customer_reaches_her_own_resource(PortalRouteEntry $entry): void
    {
        $scenario = PortalScenarioFactory::create($entry->route);
        $resource = $scenario->request($entry->resource, $scenario->a);

        [$uri, $body] = $this->target($entry, $resource, $scenario);
        $response = $this->send($entry, $uri, $body, $scenario);
        $status = $response->baseResponse->getStatusCode();

        self::assertLessThan(400, $status, sprintf(
            'Positive control: %s answered %d on the customer\'s own resource. A route that refuses its own '
            .'customer too would pass the IDOR case without proving any isolation.',
            $entry->describe(),
            $status,
        ));

        self::assertNotSame($entry->expected?->status(), $status, sprintf(
            'Positive control: %s answered %d on the customer\'s own resource — the very answer it gives for '
            .'a resource of another customer: nothing proves the route ever reached the resource.',
            $entry->describe(),
            $status,
        ));

        $this->assertControlEffect($entry, $scenario);
    }

    #[Test]
    #[DataProvider('publicDoorCases')]
    public function a_public_door_answers_a_foreign_project_as_a_project_that_does_not_exist(PortalRouteEntry $entry): void
    {
        $scenario = PortalScenarioFactory::create();
        $foreign = $scenario->request(PortalResourceNature::Project, $scenario->other);

        [$foreignUri, $foreignBody] = $this->target($entry, $foreign, $scenario);
        [$missingUri, $missingBody] = $this->target($entry, new ResourceRequest(['projectId' => (string) Str::uuid()]), $scenario);

        $foreignResponse = $this->send($entry, $foreignUri, $foreignBody, $scenario);
        $missingResponse = $this->send($entry, $missingUri, $missingBody, $scenario);

        self::assertSame($missingResponse->baseResponse->getStatusCode(), $foreignResponse->baseResponse->getStatusCode(), sprintf(
            '%s answers %d for a project of another tenant but %d for a project that does not exist: '
            .'the difference discloses the existence of the project.',
            $entry->describe(),
            $foreignResponse->baseResponse->getStatusCode(),
            $missingResponse->baseResponse->getStatusCode(),
        ));

        self::assertSame($this->html($missingResponse), $this->html($foreignResponse), sprintf(
            '%s answers a different page for a project of another tenant: the page discloses its existence.',
            $entry->describe(),
        ));
    }

    #[Test]
    public function a_private_offer_of_the_project_is_not_reachable_by_guessing_its_code(): void
    {
        // The customer has no live contract: the refusal is then about the
        // offer and not about her own contract.
        $scenario = PortalScenarioFactory::create('portal.plans.subscribe');
        $scenario->privateOffer($scenario->a->projectId, 'offre-privee');

        /** @var TestResponse<Response> $response */
        $response = $this->call('POST', '/portal/plans/offre-privee/subscribe', [
            'nonce' => self::NONCE,
            '_csrf' => $scenario->csrf,
        ], ['portal' => $scenario->cookie]);

        self::assertSame(422, $response->baseResponse->getStatusCode(), sprintf(
            'The portal answered %d for a private offer of the project: a negotiated price is never proposed to '
            .'a customer (ADR-0075 §3).',
            $response->baseResponse->getStatusCode(),
        ));

        self::assertSame(1, DB::table('subscriptions')->where('customer_id', $scenario->a->customerId)->count(), sprintf(
            'The portal signed a contract on a private offer: the price negotiated with another customer is not '
            .'for her (ADR-0075 §3).',
        ));
    }

    #[Test]
    public function the_entry_link_works_once_and_only_once(): void
    {
        $scenario = PortalScenarioFactory::create();
        $token = PortalScenarioFactory::openLink($scenario->a->projectId, $scenario->a->customerRef);

        $response = $this->get('/portal/enter/'.$token);
        $response->assertRedirect('/portal');

        $cookie = $this->portalCookie($response);
        self::assertNotNull($cookie, 'The link must open a session.');
        self::assertNotSame($token, $cookie->getValue(), 'The cookie must be another secret than the link.');

        // The link is spent: a second use finds nothing left to claim.
        $this->get('/portal/enter/'.$token)->assertStatus(410);
    }

    #[Test]
    public function an_expired_entry_link_is_refused(): void
    {
        $scenario = PortalScenarioFactory::create();
        $token = PortalScenarioFactory::openLink($scenario->a->projectId, $scenario->a->customerRef);

        DB::table('portal_sessions')
            ->where('customer_id', $scenario->a->customerId)
            ->update(['token_expires_at' => now()->subMinute()]);

        $this->get('/portal/enter/'.$token)->assertStatus(410);
    }

    #[Test]
    public function the_entry_link_opens_the_session_of_its_own_project_and_its_own_customer(): void
    {
        $scenario = PortalScenarioFactory::create();
        $token = PortalScenarioFactory::openLink($scenario->a->projectId, $scenario->a->customerRef);

        $cookie = $this->portalCookie($this->get('/portal/enter/'.$token));
        self::assertNotNull($cookie);
        $value = $cookie->getValue();
        self::assertIsString($value);

        // The session the link opened belongs to the project of the link.
        $session = DB::table('portal_sessions')->where('customer_id', $scenario->a->customerId)->first();
        self::assertNotNull($session);
        self::assertSame($scenario->a->projectId, $session->project_id);

        // The resources of another project are not visible from it.
        $this->withUnencryptedCookie('portal', $value)
            ->get('/portal/invoices/'.$scenario->other->invoiceId)
            ->assertStatus(404);

        // Neither are those of the neighbour of the same project.
        $this->withUnencryptedCookie('portal', $value)
            ->get('/portal/invoices/'.$scenario->b->invoiceId)
            ->assertStatus(404);
    }

    #[Test]
    public function a_link_issued_for_another_customers_invoice_is_refused(): void
    {
        $scenario = PortalScenarioFactory::create();

        $this->expectException(InvalidPortalFlow::class);
        $this->expectExceptionMessage('does not belong to this customer');

        app(OpenPortalSessionUseCase::class)->open(new OpenPortalSessionCommand(
            projectId: $scenario->a->projectId,
            customerRef: $scenario->a->customerRef,
            flow: PortalSession::FLOW_PAY_INVOICE,
            invoiceId: $scenario->b->invoiceId,
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'no provider in the IDOR guard'], 503)]);
        Queue::fake();

        // The payment port, watched: the portal must not even try to
        // collect the invoice of another customer.
        $this->payments = new PortalPaymentSpy;
        $this->app->instance(PayInvoice::class, $this->payments);

        // A platform that can route the channels: the invoice page offers
        // the channels the platform routes, and the positive control needs
        // one of them to answer.
        $this->app->instance(PaymentProviderRouter::class, new class implements PaymentProviderRouter
        {
            public function resolveProvider(PaymentChannel $channel, string $projectId): ProviderCode
            {
                return ProviderCode::fromString('test-provider');
            }
        });
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    private function route(PortalRouteEntry $entry): Route
    {
        $route = RouteFacade::getRoutes()->getByName($entry->route);
        self::assertInstanceOf(Route::class, $route, sprintf('The route "%s" is not registered any more.', $entry->route));

        return $route;
    }

    /**
     * An identifier nobody ever had: the answer for it is the one the
     * portal must give for a resource of another customer.
     */
    private function missingResource(PortalRouteEntry $entry): ResourceRequest
    {
        return match ($entry->resource) {
            PortalResourceNature::Invoice => new ResourceRequest(['invoiceId' => (string) Str::uuid()]),
            PortalResourceNature::Subscription => new ResourceRequest(['subscriptionId' => (string) Str::uuid()]),
            PortalResourceNature::Plan => new ResourceRequest(['planCode' => 'offre-inexistante']),
            default => new ResourceRequest([]),
        };
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function target(PortalRouteEntry $entry, ResourceRequest $resource, PortalScenarioFactory $scenario): array
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

        return [$uri, $this->bodyFor($entry, $scenario)];
    }

    /**
     * A body valid for the route: a request the portal refuses on the form
     * would prove nothing about the isolation — the gesture has to reach
     * the use case.
     *
     * @return array<string, mixed>
     */
    private function bodyFor(PortalRouteEntry $entry, PortalScenarioFactory $scenario): array
    {
        $body = match ($entry->route) {
            'portal.invoice.pay' => ['channel' => 'mtn_momo', 'phone' => '+237690000000', 'nonce' => self::NONCE],
            'portal.subscriptions.change-plan' => ['plan' => $scenario->secondPlan],
            'portal.subscriptions.renew' => ['nonce' => self::NONCE],
            'portal.plans.subscribe' => ['nonce' => self::NONCE],
            'portal.login.send' => ['phone' => '+237690000000'],
            'portal.login.verify' => ['phone' => '+237690000000', 'login_pin' => '123456'],
            default => [],
        };

        // The state-changing pages of the portal carry their anti-forgery
        // token; the public doors have no session yet.
        if ($entry->coverage === PortalCoverage::Isolated) {
            $body['_csrf'] = $scenario->csrf;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return TestResponse<Response>
     */
    private function send(PortalRouteEntry $entry, string $uri, array $body, PortalScenarioFactory $scenario): TestResponse
    {
        $method = $this->methodOf($this->route($entry));

        // The cookie of the portal is the session: `call()` takes the
        // cookies of the request as an argument (the `withUnencryptedCookie`
        // helper only feeds the `get()`/`post()` shortcuts).
        $cookies = $entry->coverage === PortalCoverage::Isolated ? ['portal' => $scenario->cookie] : [];

        /** @var TestResponse<Response> $response */
        $response = $this->call($method, $uri, $body, $cookies);

        return $response;
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
     * The gesture the positive control made really happened: a route that
     * answers without touching anything proves nothing.
     */
    private function assertControlEffect(PortalRouteEntry $entry, PortalScenarioFactory $scenario): void
    {
        $subscription = DB::table('subscriptions')->where('id', $scenario->a->subscriptionId);

        switch ($entry->route) {
            case 'portal.subscriptions.cancel':
                self::assertTrue((bool) $subscription->value('cancel_at_period_end'), sprintf(
                    'Positive control: %s answered without arming the end of the contract.',
                    $entry->describe(),
                ));

                break;

            case 'portal.subscriptions.keep':
                self::assertFalse((bool) $subscription->value('cancel_at_period_end'), sprintf(
                    'Positive control: %s answered without taking the end of the contract back.',
                    $entry->describe(),
                ));

                break;

            case 'portal.subscriptions.change-plan':
                self::assertNotNull($subscription->value('pending_plan_version_id'), sprintf(
                    'Positive control: %s answered without preparing the new terms.',
                    $entry->describe(),
                ));

                break;

            case 'portal.subscriptions.renew':
            case 'portal.plans.subscribe':
                self::assertSame(2, DB::table('subscriptions')->where('customer_id', $scenario->a->customerId)->count(), sprintf(
                    'Positive control: %s answered without signing the contract the customer asked for.',
                    $entry->describe(),
                ));

                break;

            case 'portal.invoice.pay':
                self::assertCount(1, $this->payments->commands(), sprintf(
                    'Positive control: %s answered without starting the collection of the invoice.',
                    $entry->describe(),
                ));

                break;

            default:
                break;
        }
    }

    /**
     * The cookie the portal hands back when a link is exchanged.
     */
    /**
     * @param TestResponse<Response> $response
     */
    private function portalCookie(TestResponse $response): ?Cookie
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'portal' || $cookie->getName() === '__Host-portal') {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function html(TestResponse $response): string
    {
        // Larastan models TestResponse's forwarded getContent() as static.
        $content = $response->getContent(); // @phpstan-ignore staticMethod.dynamicCall

        return is_string($content) ? $content : '';
    }
}
