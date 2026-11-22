<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Billing\ClientApplication\ClientApplicationEndpoint;
use App\Billing\ClientApplication\ClientApplicationReadFailed;
use App\Billing\ClientApplication\HttpClientApplicationReader;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Webhook\Contracts\SignedEndpointRequest;
use Core\Webhook\Contracts\SignEndpointRequest;
use Core\Webhook\Contracts\SignEndpointRequestCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0133 §1, §3, §7 — la lecture d'une application cliente : ce que le
 * Core demande, comment il le signe, et ce qu'il comprend quand la
 * réponse n'est pas celle qu'il attendait.
 */
final class HttpClientApplicationReaderTest extends TestCase
{
    #[Test]
    public function it_reads_the_form_the_application_declares(): void
    {
        $transport = new RecordingTransport(new ExternalResponse(200, [], [
            'unique_key' => 'code',
            'unique_key_pattern' => 'AE[0-9]{4}',
            'fields' => [
                [
                    'key' => 'localisation',
                    'label' => 'Localisation',
                    'type' => 'string',
                    'required' => true,
                    'max_length' => 120,
                    'source' => 'declared.localisation',
                ],
            ],
        ]));

        $signer = new RecordingSigner;
        $reader = new HttpClientApplicationReader($transport, $signer);

        $form = $reader->tenantForm($this->endpoint());

        self::assertSame('code', $form['unique_key']);
        self::assertSame('AE[0-9]{4}', $form['unique_key_pattern']);
        self::assertCount(1, $form['fields']);
        self::assertSame('localisation', $form['fields'][0]['key']);
        self::assertTrue($form['fields'][0]['required']);
        self::assertSame(120, $form['fields'][0]['max_length']);

        // The request went to the route the package serves, without a body.
        self::assertSame(HttpMethod::GET, $transport->method);
        self::assertSame('https://app.example.test/core/tenant-schema', $transport->url);
        self::assertNull($transport->body);

        // And it was signed — with the endpoint, and with the body it
        // carries (ADR-0133 §3).
        $command = $signer->command;
        self::assertNotNull($command);
        self::assertSame('endpoint-1', $command->endpointId);
        self::assertSame('project-1', $command->projectId);
        self::assertSame('', $command->body);
    }

    #[Test]
    public function the_page_is_carried_by_the_signed_body(): void
    {
        $transport = new RecordingTransport(new ExternalResponse(200, [], [
            'data' => [],
            'meta' => ['page' => 2, 'per_page' => 100, 'count' => 0, 'has_more' => false],
        ]));

        $signer = new RecordingSigner;
        $reader = new HttpClientApplicationReader($transport, $signer);

        $answer = $reader->tenants($this->endpoint(), 2, 100);

        self::assertSame([], $answer['rows']);
        self::assertFalse($answer['has_more']);

        self::assertSame(HttpMethod::POST, $transport->method);
        self::assertSame('https://app.example.test/core/tenants', $transport->url);
        self::assertSame('{"page":2,"per_page":100}', $transport->body);

        // The signed body is the very body that travels.
        $command = $signer->command;
        self::assertNotNull($command);
        self::assertSame('{"page":2,"per_page":100}', $command->body);
    }

    #[Test]
    public function it_reads_the_tenants_the_application_already_has(): void
    {
        $transport = new RecordingTransport(new ExternalResponse(200, [], [
            'data' => [
                ['external_ref' => 'AE0001', 'display_name' => 'Auto-école Soleil'],
            ],
            'meta' => ['page' => 1, 'per_page' => 100, 'count' => 1, 'has_more' => true],
        ]));

        $reader = new HttpClientApplicationReader($transport, new RecordingSigner);

        $answer = $reader->tenants($this->endpoint(), 1, 100);

        self::assertCount(1, $answer['rows']);
        self::assertSame('AE0001', $answer['rows'][0]['external_ref']);
        self::assertTrue($answer['has_more']);
    }

    #[Test]
    public function a_refused_signature_says_the_secret_does_not_match(): void
    {
        $reader = new HttpClientApplicationReader(
            new RecordingTransport(new ExternalResponse(401, [], ['error' => 'invalid_signature'])),
            new RecordingSigner,
        );

        $this->expectException(ClientApplicationReadFailed::class);
        $this->expectExceptionMessage('Le secret ne correspond pas');

        $reader->tenantForm($this->endpoint());
    }

    #[Test]
    public function an_application_that_does_not_know_the_route_is_told_to_be_updated(): void
    {
        $reader = new HttpClientApplicationReader(
            new RecordingTransport(new ExternalResponse(404)),
            new RecordingSigner,
        );

        $this->expectException(ClientApplicationReadFailed::class);
        $this->expectExceptionMessage('version 1.7 du paquet');

        $reader->tenantForm($this->endpoint());
    }

    #[Test]
    public function an_application_that_declares_no_form_says_so(): void
    {
        $reader = new HttpClientApplicationReader(
            new RecordingTransport(new ExternalResponse(422, [], ['error' => 'tenant_schema_not_declared'])),
            new RecordingSigner,
        );

        $this->expectException(ClientApplicationReadFailed::class);
        $this->expectExceptionMessage('ne déclare aucune forme');

        $reader->tenantForm($this->endpoint());
    }

    #[Test]
    public function an_application_that_cannot_list_its_tenants_says_so(): void
    {
        $reader = new HttpClientApplicationReader(
            new RecordingTransport(new ExternalResponse(422, [], ['error' => 'tenant_listing_not_supported'])),
            new RecordingSigner,
        );

        $this->expectException(ClientApplicationReadFailed::class);
        $this->expectExceptionMessage('ne sait pas lister');

        $reader->tenants($this->endpoint(), 1, 100);
    }

    #[Test]
    public function an_application_that_does_not_answer_says_so(): void
    {
        $reader = new HttpClientApplicationReader(
            new FailingTransport,
            new RecordingSigner,
        );

        $this->expectException(ClientApplicationReadFailed::class);
        $this->expectExceptionMessage('n\'a pas répondu');

        $reader->tenantForm($this->endpoint());
    }

    #[Test]
    public function an_answer_the_core_cannot_read_is_refused(): void
    {
        $reader = new HttpClientApplicationReader(
            new RecordingTransport(new ExternalResponse(200, [], ['unexpected' => true])),
            new RecordingSigner,
        );

        $this->expectException(ClientApplicationReadFailed::class);
        $this->expectExceptionMessage('ne sait pas lire');

        $reader->tenantForm($this->endpoint());
    }

    private function endpoint(): ClientApplicationEndpoint
    {
        return new ClientApplicationEndpoint('project-1', 'endpoint-1', 'https://app.example.test');
    }
}

/**
 * The transport, and what it was asked.
 */
final class RecordingTransport implements HttpTransport
{
    public ?HttpMethod $method = null;

    public ?string $url = null;

    /** @var array<string, string> */
    public array $headers = [];

    public ?string $body = null;

    public function __construct(private readonly ExternalResponse $response) {}

    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = is_string($body) ? $body : null;

        return $this->response;
    }
}

/**
 * The transport that never reaches the application.
 */
final class FailingTransport implements HttpTransport
{
    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse {
        throw new ProviderPermanentError('Connection refused.', ProviderCode::fromString('transport'));
    }
}

/**
 * The signer, and what it was asked to sign.
 */
final class RecordingSigner implements SignEndpointRequest
{
    public ?SignEndpointRequestCommand $command = null;

    public function sign(SignEndpointRequestCommand $command): SignedEndpointRequest
    {
        $this->command = $command;

        return new SignedEndpointRequest([
            'X-Signature' => 'signature',
            'X-Timestamp' => '1767225600',
        ]);
    }
}
