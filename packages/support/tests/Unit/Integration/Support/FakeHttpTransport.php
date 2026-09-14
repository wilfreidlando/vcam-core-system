<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Support;

use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Transport\HttpTransport;
use LogicException;
use Throwable;

/**
 * In-memory `HttpTransport` for tests — records sent requests and replays
 * queued responses. Throws a controllable error when the queue is empty
 * so test authors cannot accidentally "succeed" by sending fewer
 * requests than expected.
 *
 * The fixture is intentionally simple: there is no concurrency, no
 * streaming, no real sockets. It only proves that the `ExternalApiCaller`
 * pipeline (auth → signature → transport → retry → classification) does
 * what the contract says.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{method: HttpMethod, url: string, headers: array<string,string>, body: mixed}> */
    public array $sent = [];

    public int $sendCallCount = 0;

    /** @var list<ExternalResponse|TransportExceptionStub> */
    private array $queue = [];

    /**
     * Queue a canned response. The next call to `send()` pops it.
     * Pass a `TransportExceptionStub` to simulate a transport-level failure.
     */
    public function queue(ExternalResponse|TransportExceptionStub $item): void
    {
        $this->queue[] = $item;
    }

    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse {
        $this->sent[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
        $this->sendCallCount++;

        if ($this->queue === []) {
            throw new LogicException(sprintf(
                'FakeHttpTransport: no canned response queued for call #%d (%s %s). '.
                'The test forgot to queue a response.',
                $this->sendCallCount,
                $method->value,
                $url,
            ));
        }

        $item = array_shift($this->queue);
        if ($item instanceof TransportExceptionStub) {
            throw $item->throwable;
        }

        return $item;
    }
}

/**
 * Throwable to throw when the fake transport pops this entry from its
 * queue. Lets tests simulate connection failures, timeouts, etc.
 */
final class TransportExceptionStub
{
    public function __construct(public readonly Throwable $throwable) {}
}
