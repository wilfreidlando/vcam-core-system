<?php

declare(strict_types=1);

namespace Core\Support\Http\Middleware;

use Closure;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-0059 — the two ends of every HTTP exchange, as two structured log
 * lines: `http.request.received` (what the caller sent) and
 * `http.response.sent` (what the Core answered, how long it took). Both
 * carry `correlation_id`/`trace_id` (and `project_id`/`operator_id` once
 * authenticated) through Laravel's Context, so a single Loki query on the
 * correlation id replays the whole request, every provider call and every
 * job it caused.
 *
 * Secrets never reach the log: headers and bodies go through the same
 * `PayloadRedactor` as provider calls (authorization, keys, signatures,
 * passwords, tokens…). Bodies are truncated to `max_body_bytes`; non-JSON
 * responses (admin HTML pages) are logged by size only.
 */
final readonly class LogHttpExchange
{
    /** Deeper than this, a JSON body is not decoded for the log (the body limiter refuses it anyway). */
    private const MAX_LOGGED_DEPTH = 21;

    public function __construct(
        private LoggerInterface $logger,
        private PayloadRedactor $redactor,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled() || $this->isExcluded($request)) {
            return $next($request);
        }

        $startedAt = hrtime(true);

        $this->logger->info('http.request.received', [
            'http' => [
                'method' => $request->getMethod(),
                'path' => $this->path($request),
                'query' => $this->redactor->redact($request->query->all()),
                'client_ip' => $request->ip(),
                'user_agent' => $this->truncate((string) $request->userAgent()),
                'headers' => $this->redactor->redactHeaders($this->flattenHeaders($request)),
                'body' => $this->requestBody($request),
            ],
        ]);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->logger->error('http.response.sent', [
                'http' => [
                    'method' => $request->getMethod(),
                    'path' => $this->path($request),
                    'route' => $request->route()?->getName(),
                    'status' => 500,
                    'duration_ms' => $this->elapsedMs($startedAt),
                    'exception' => $e::class,
                ],
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();
        $context = [
            'http' => [
                'method' => $request->getMethod(),
                'path' => $this->path($request),
                'route' => $request->route()?->getName(),
                'status' => $status,
                'duration_ms' => $this->elapsedMs($startedAt),
                'response_bytes' => strlen((string) $response->getContent()),
                'body' => $this->responseBody($response),
            ],
        ];

        match (true) {
            $status >= 500 => $this->logger->error('http.response.sent', $context),
            // A 404 on a path no route matches is Internet noise (scanners probing /.env, /wp-login.php…): the Core
            // answered correctly and nothing is actionable. Logged as a WARNING it drowned the real ones (311 of 317
            // in 24 h on 2026-10-05). The line is still written, at info, so it stays searchable by path.
            $status === 404 && $request->route() === null => $this->logger->info('http.response.sent', $context),
            $status >= 400 => $this->logger->warning('http.response.sent', $context),
            default => $this->logger->info('http.response.sent', $context),
        };

        return $response;
    }

    private function path(Request $request): string
    {
        $scrubbed = $this->scrub('/'.ltrim($request->path(), '/'));

        return is_string($scrubbed) ? $scrubbed : '/';
    }

    /**
     * Masks what a key-based redaction cannot see: a secret that lives in a
     * URL (a one-time link is `…/portal/enter/<token>`; logged, it would be
     * a working credential for ten minutes). The patterns are configuration
     * (`observability.http_log.scrub`: regex → replacement) so this shared
     * kernel knows no module.
     */
    private function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->scrub(...), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        /** @var array<string, string> $rules */
        $rules = (array) config('observability.http_log.scrub', []);

        foreach ($rules as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $value;
    }

    private function enabled(): bool
    {
        return (bool) config('observability.http_log.enabled', true);
    }

    private function isExcluded(Request $request): bool
    {
        /** @var list<string> $patterns */
        $patterns = (array) config('observability.http_log.except', []);

        return $patterns !== [] && $request->is(...$patterns);
    }

    private function requestBody(Request $request): mixed
    {
        if ($request->isJson()) {
            $content = $request->getContent();

            // Audit 2026-10-29 #13 — the whole body used to be decoded on every
            // request: with a 20 MB body, that was CPU and memory spent for a
            // log line that truncates at `max_body_bytes` anyway. Past the
            // ceiling nothing is decoded, and nothing of the raw text is logged
            // either (a prefix of an unredacted body would carry its secrets).
            if (strlen($content) > $this->maxBytes()) {
                return sprintf('(corps JSON de %d octets, non journalisé : au-delà de %d octets)', strlen($content), $this->maxBytes());
            }

            $decoded = json_decode($content, true, self::MAX_LOGGED_DEPTH);

            return is_array($decoded)
                ? $this->limit($this->scrub($this->redactor->redact($decoded)))
                : sprintf('(corps JSON illisible ou trop imbriqué, %d octets, non journalisé)', strlen($content));
        }

        $input = $request->request->all();
        if ($input === []) {
            return null;
        }

        return $this->limit($this->scrub($this->redactor->redact($input)));
    }

    private function responseBody(Response $response): mixed
    {
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains($contentType, 'json')) {
            return null;
        }

        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);

        return is_array($decoded)
            ? $this->limit($this->scrub($this->redactor->redact($decoded)))
            : $this->truncate($content);
    }

    /**
     * @return array<string, string>
     */
    private function flattenHeaders(Request $request): array
    {
        $flat = [];
        foreach ($request->headers->all() as $name => $values) {
            $flat[$name] = implode(', ', array_filter($values, is_string(...)));
        }

        unset($flat['cookie']);

        return $flat;
    }

    private function limit(mixed $value): mixed
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) <= $this->maxBytes()) {
            return $value;
        }

        return $this->truncate($encoded);
    }

    private function truncate(string $value): string
    {
        $max = $this->maxBytes();

        return strlen($value) > $max ? substr($value, 0, $max).'… (truncated)' : $value;
    }

    private function maxBytes(): int
    {
        $configured = config('observability.http_log.max_body_bytes', 16_384);

        return is_numeric($configured) ? max(256, (int) $configured) : 16_384;
    }

    private function elapsedMs(int|float $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
