<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Billing\ClientApplication\ClientApplicationEndpoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0133 §2 — l'adresse de l'application est **déduite** du point
 * d'entrée, et l'opérateur peut la corriger.
 */
final class ClientApplicationEndpointTest extends TestCase
{
    #[Test]
    public function the_address_is_derived_from_the_endpoint(): void
    {
        $endpoint = ClientApplicationEndpoint::derivedFrom(
            'project-1',
            'endpoint-1',
            'https://app.example.test/webhooks/core',
        );

        self::assertSame('https://app.example.test', $endpoint->baseUrl);
        self::assertSame('https://app.example.test/core/tenant-schema', $endpoint->url('/core/tenant-schema'));
    }

    #[Test]
    public function the_port_of_the_endpoint_is_kept(): void
    {
        $endpoint = ClientApplicationEndpoint::derivedFrom(
            'project-1',
            'endpoint-1',
            'https://app.example.test:8443/webhooks/core',
        );

        self::assertSame('https://app.example.test:8443', $endpoint->baseUrl);
    }

    #[Test]
    public function an_address_that_is_not_https_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientApplicationEndpoint::derivedFrom(
            'project-1',
            'endpoint-1',
            'http://app.example.test/webhooks/core',
        );
    }

    #[Test]
    public function the_loopback_may_speak_in_http(): void
    {
        $endpoint = ClientApplicationEndpoint::derivedFrom(
            'project-1',
            'endpoint-1',
            'http://localhost:8000/webhooks/core',
        );

        self::assertSame('http://localhost:8000', $endpoint->baseUrl);
    }

    #[Test]
    public function the_operator_may_correct_the_address(): void
    {
        $endpoint = ClientApplicationEndpoint::at('project-1', 'endpoint-1', 'https://api.example.test/');

        self::assertSame('https://api.example.test', $endpoint->baseUrl);
        self::assertSame('https://api.example.test/core/tenants', $endpoint->url('/core/tenants'));
    }

    #[Test]
    public function a_corrected_address_that_is_not_https_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientApplicationEndpoint::at('project-1', 'endpoint-1', 'http://api.example.test');
    }
}
