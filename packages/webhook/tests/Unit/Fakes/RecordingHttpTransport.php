<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Transport\HttpTransport;

/**
 * In-memory `HttpTransport` — records every call and always answers 200.
 * Only what `HttpWebhookSenderTest` needs: inspecting the exact body/headers
 * `HttpWebhookSender` composed, never real I/O.
 */
final class RecordingHttpTransport implements HttpTransport
{
    /** @var list<array{method: HttpMethod, url: string, headers: array<string,string>, body: mixed}> */
    public array $sent = [];

    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return new ExternalResponse(status: 200);
    }
}
