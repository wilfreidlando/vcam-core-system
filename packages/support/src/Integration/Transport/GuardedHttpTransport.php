<?php

declare(strict_types=1);

namespace Core\Support\Integration\Transport;

use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Security\OutboundUrlGuard;

/**
 * The transport for URLs a Project chose itself (webhook endpoints,
 * payment `callback_url`) — never a catalogued provider's base URL.
 *
 * Audit 2026-10-03 §2.1: validation-time checks alone are not enough.
 * At every send this transport re-resolves the host, refuses the call if
 * any address is not publicly routable, and pins the connection to the
 * checked address. Redirects are already refused by the inner transport
 * (`LaravelHttpTransport`), so a public URL answering `302 Location:
 * http://169.254.169.254/` cannot pivot either.
 */
final readonly class GuardedHttpTransport implements HttpTransport
{
    public function __construct(
        private HttpTransport $inner,
        private OutboundUrlGuard $guard,
        private bool $enforce = true,
    ) {}

    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse {
        if (! $this->enforce) {
            return $this->inner->send($method, $url, $headers, $body, $timeoutSeconds);
        }

        $addresses = $this->guard->resolvePublicAddresses($url);
        if ($addresses === []) {
            throw new ProviderPermanentError(
                'Destination URL is not publicly reachable (blocked or unresolvable host).',
                ProviderCode::fromString('transport'),
            );
        }

        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $port = is_array($parts) && isset($parts['port']) ? $parts['port'] : ($scheme === 'http' ? 80 : 443);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Literal IP — nothing to resolve, nothing to pin.
            return $this->inner->send($method, $url, $headers, $body, $timeoutSeconds);
        }

        $transport = $this->inner instanceof PinnableHttpTransport
            ? $this->inner->pinnedTo($host, $port, $addresses[0])
            : $this->inner;

        return $transport->send($method, $url, $headers, $body, $timeoutSeconds);
    }
}
