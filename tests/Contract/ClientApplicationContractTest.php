<?php

declare(strict_types=1);

namespace Tests\Contract;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Webhook\Contracts\WebhookEventTypes;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0109 §3 — the Core's half of the contract with the client package.
 *
 * The package lives in another repository and proves itself against a deployed
 * Core. This test confronts **what it needs** with the Core as it is today:
 * the routing table, the event catalogue, the published document and the scope
 * templates. A change that breaks the package is caught here, the day it is
 * made — not at the package's next run against staging.
 */
final class ClientApplicationContractTest extends TestCase
{
    #[Test]
    public function every_endpoint_the_package_calls_is_still_routed(): void
    {
        $routed = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routed[strtolower($method).' '.$route->uri()] = true;
            }
        }

        foreach ($this->endpoints() as $endpoint) {
            self::assertArrayHasKey(
                $endpoint['method'].' v1/'.ltrim($endpoint['path'], '/'),
                $routed,
                sprintf('The package calls %s %s — the Core no longer routes it.', $endpoint['method'], $endpoint['path']),
            );
        }
    }

    #[Test]
    public function every_scope_the_package_needs_exists(): void
    {
        foreach ($this->scopes() as $scope) {
            self::assertContains(
                $scope,
                ApiKeyScopes::ALL,
                sprintf('`%s` is not a scope of the Core anymore.', $scope),
            );
        }
    }

    #[Test]
    public function the_client_application_template_holds_every_scope_the_package_needs(): void
    {
        $template = ApiKeyScopes::template(ApiKeyScopes::TEMPLATE_CLIENT_APP);

        foreach (ClientApplicationContractFixture::SCOPES as $scope) {
            self::assertContains(
                $scope,
                $template,
                sprintf('The « Application cliente » template no longer holds `%s`: the package would be refused.', $scope),
            );
        }
    }

    #[Test]
    public function every_event_the_package_subscribes_to_can_still_be_emitted(): void
    {
        foreach (ClientApplicationContractFixture::EVENTS as $event) {
            self::assertContains(
                $event,
                WebhookEventTypes::ALL,
                sprintf('`%s` can no longer be subscribed to by name.', $event),
            );
        }
    }

    #[Test]
    public function the_package_holds_no_scope_that_would_allow_commerce(): void
    {
        // The fixture's required endpoints are all reads, one counter and one
        // portal session. If one of them ever needed a write scope on the
        // commerce, the client application would be doing the commerce itself
        // (ADR-0107 §1) — this is where that would show up.
        $forbidden = ['customers:write', 'subscriptions:write', 'invoices:write', 'plans:write'];

        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint['scope'] === null) {
                continue;
            }

            self::assertNotContains(
                $endpoint['scope'],
                $forbidden,
                sprintf('`%s %s` would give a client application the power to do commerce.', $endpoint['method'], $endpoint['path']),
            );
        }
    }

    /**
     * Every endpoint the package calls: the required ones and the optional
     * ones.
     *
     * @return list<array{method: string, path: string, scope: string|null}>
     */
    private function endpoints(): array
    {
        $endpoints = ClientApplicationContractFixture::REQUIRED;

        foreach (ClientApplicationContractFixture::OPTIONAL as $group) {
            foreach ($group as $endpoint) {
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    /**
     * Every scope the package needs: the required ones and the optional ones.
     *
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = ClientApplicationContractFixture::SCOPES;

        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint['scope'] !== null) {
                $scopes[] = $endpoint['scope'];
            }
        }

        return array_values(array_unique($scopes));
    }
}
