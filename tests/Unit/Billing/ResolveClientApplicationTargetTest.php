<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Billing\ClientApplication\ClientApplicationConnection;
use App\Billing\ClientApplication\ClientApplicationConnections;
use App\Billing\ClientApplication\ResolveClientApplicationTarget;
use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0133 §2 — à qui le Core parle, et où.
 *
 * L'adresse est **déduite** du point d'entrée ; l'opérateur peut la
 * corriger, et le Core ne devine jamais lequel de plusieurs points
 * d'entrée il doit appeler.
 */
final class ResolveClientApplicationTargetTest extends TestCase
{
    private InMemoryConnections $connections;

    #[Test]
    public function without_an_endpoint_the_core_says_what_is_missing(): void
    {
        $target = $this->resolver()->resolve('project-1', AccessScope::platform());

        self::assertFalse($target->isReady());
        self::assertNull($target->endpointId);
        self::assertNull($target->baseUrl);
        self::assertSame(
            'Enregistrez d\'abord le point d\'entrée des événements de l\'application.',
            $target->reason,
        );
    }

    #[Test]
    public function the_address_is_derived_from_the_only_endpoint(): void
    {
        $target = $this->resolver([
            $this->endpoint('endpoint-1', 'https://app.example.test/webhooks/core'),
        ])->resolve('project-1', AccessScope::platform());

        self::assertTrue($target->isReady());
        self::assertSame('endpoint-1', $target->endpointId);
        self::assertSame('https://app.example.test', $target->baseUrl);
        self::assertNull($target->reason);
    }

    #[Test]
    public function the_port_of_the_endpoint_is_kept(): void
    {
        $target = $this->resolver([
            $this->endpoint('endpoint-1', 'https://app.example.test:8443/webhooks/core'),
        ])->resolve('project-1', AccessScope::platform());

        self::assertSame('https://app.example.test:8443', $target->baseUrl);
    }

    #[Test]
    public function several_endpoints_and_the_operator_chooses(): void
    {
        $target = $this->resolver([
            $this->endpoint('endpoint-1', 'https://one.example.test/webhooks/core'),
            $this->endpoint('endpoint-2', 'https://two.example.test/webhooks/core'),
        ])->resolve('project-1', AccessScope::platform());

        self::assertFalse($target->isReady());
        self::assertNull($target->endpointId);
        self::assertCount(2, $target->choices);
        self::assertSame(
            'Plusieurs points d\'entrée sont enregistrés : choisissez celui qui reçoit les événements de l\'application.',
            $target->reason,
        );
    }

    #[Test]
    public function an_inactive_endpoint_is_not_a_choice(): void
    {
        $target = $this->resolver([
            $this->endpoint('endpoint-1', 'https://one.example.test/webhooks/core'),
            $this->endpoint('endpoint-2', 'https://two.example.test/webhooks/core', active: false),
        ])->resolve('project-1', AccessScope::platform());

        self::assertTrue($target->isReady());
        self::assertSame('endpoint-1', $target->endpointId);
    }

    #[Test]
    public function the_chosen_branching_wins_over_the_deduction(): void
    {
        $resolver = $this->resolver([
            $this->endpoint('endpoint-1', 'https://one.example.test/webhooks/core'),
            $this->endpoint('endpoint-2', 'https://two.example.test/webhooks/core'),
        ]);

        $this->connections->save(new ClientApplicationConnection(
            projectId: 'project-1',
            endpointId: 'endpoint-2',
            baseUrl: null,
        ));

        $target = $resolver->resolve('project-1', AccessScope::platform());

        self::assertTrue($target->isReady());
        self::assertSame('endpoint-2', $target->endpointId);
        self::assertSame('https://two.example.test', $target->baseUrl);
    }

    #[Test]
    public function the_corrected_address_is_kept(): void
    {
        $resolver = $this->resolver([
            $this->endpoint('endpoint-1', 'https://app.example.test/webhooks/core'),
        ]);

        $this->connections->save(new ClientApplicationConnection(
            projectId: 'project-1',
            endpointId: 'endpoint-1',
            baseUrl: 'https://api.example.test',
        ));

        $target = $resolver->resolve('project-1', AccessScope::platform());

        self::assertSame('https://api.example.test', $target->baseUrl);
    }

    #[Test]
    public function an_address_that_is_not_https_is_refused(): void
    {
        $target = $this->resolver([
            $this->endpoint('endpoint-1', 'http://app.example.test/webhooks/core'),
        ])->resolve('project-1', AccessScope::platform());

        self::assertFalse($target->isReady());
        self::assertSame(
            'L\'adresse du point d\'entrée n\'est pas en HTTPS : le Core ne peut pas appeler l\'application.',
            $target->reason,
        );
    }

    /**
     * @param list<WebhookEndpointView> $endpoints
     */
    private function resolver(array $endpoints = []): ResolveClientApplicationTarget
    {
        $this->connections = new InMemoryConnections;

        return new ResolveClientApplicationTarget(
            new FixedEndpoints($endpoints),
            $this->connections,
        );
    }

    private function endpoint(string $id, string $url, bool $active = true): WebhookEndpointView
    {
        return new WebhookEndpointView($id, $url, ['*'], $active);
    }
}

/**
 * The points d'entrée of the project.
 */
final class FixedEndpoints implements ListWebhookEndpoints
{
    /**
     * @param list<WebhookEndpointView> $endpoints
     */
    public function __construct(private readonly array $endpoints) {}

    public function list(ListWebhookEndpointsCommand $command, AccessScope $scope): array
    {
        return $this->endpoints;
    }
}

/**
 * The branchings kept, in memory.
 */
final class InMemoryConnections implements ClientApplicationConnections
{
    /**
     * @var array<string, ClientApplicationConnection>
     */
    private array $connections = [];

    public function find(string $projectId): ?ClientApplicationConnection
    {
        return $this->connections[$projectId] ?? null;
    }

    public function save(ClientApplicationConnection $connection): void
    {
        $this->connections[$connection->projectId] = $connection;
    }
}
