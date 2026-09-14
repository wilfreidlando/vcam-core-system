<?php

declare(strict_types=1);

namespace Core\Support\Integration;

use Core\Support\Clock;
use Core\Support\Integration\Auth\ApiKeyAuthProvider;
use Core\Support\Integration\Auth\AuthProvider;
use Core\Support\Integration\Auth\BasicAuthProvider;
use Core\Support\Integration\Auth\BearerAuthProvider;
use Core\Support\Integration\Auth\NullTokenCache;
use Core\Support\Integration\Auth\TokenCache;
use Core\Support\Integration\CircuitBreaker\CircuitBreaker;
use Core\Support\Integration\CircuitBreaker\InMemoryCircuitBreaker;
use Core\Support\Integration\Configuration\CircuitBreakerPolicy;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Core\Support\Integration\Redaction\SensitiveKeySet;
use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Support\Integration\Transport\LaravelHttpTransport;
use Core\Support\SystemClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the integration foundation into the Laravel container.
 *
 * Bindings:
 *  - VOs (`PayloadRedactor`, `CircuitBreakerPolicy` default) — singletons.
 *  - Ports (`HttpTransport`, `CircuitBreaker`, `RequestSigner`,
 *    `TokenCache`) — default in-process implementations, app shell can
 *    rebind (notably `TokenCache` to Valkey for any future OAuth2
 *    flow that needs it).
 *  - `AuthProvider` — tagged iterable; the foundation ships ApiKey,
 *    Basic and Bearer (static). The tag is `integration.auth_provider`;
 *    `ExternalApiCallerImpl` consumes the iterable.
 *  - `ExternalApiCaller` — the foundation interface, bound to
 *    `ExternalApiCallerImpl`.
 *
 * Defaults:
 *  - `Clock` → `SystemClock`. The application may rebind to a frozen
 *    clock in tests; this provider performs the default binding only
 *    when none has been set.
 *  - `TokenCache` → `NullTokenCache`. Production callers using an
 *    OAuth2-style flow (when one is added) must rebind to a real cache.
 *    The null sentinel fails loudly on the first `put()` call. With
 *    the static-token providers currently shipped, the cache is unused
 *    and the binding is dormant.
 */
final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(Clock::class, SystemClock::class);

        $this->app->singleton(PayloadRedactor::class, function (): PayloadRedactor {
            return new PayloadRedactor(new SensitiveKeySet);
        });

        $this->app->singleton(SensitiveKeySet::class, fn (): SensitiveKeySet => new SensitiveKeySet);

        $this->app->singleton(CircuitBreaker::class, function (Application $app): CircuitBreaker {
            return new InMemoryCircuitBreaker(
                $app->make(Clock::class),
                new CircuitBreakerPolicy,
            );
        });

        $this->app->singleton(RequestSigner::class, HmacSha256RequestSigner::class);

        $this->app->singleton(HttpTransport::class, function (Application $app): HttpTransport {
            return new LaravelHttpTransport(
                $app->make(HttpFactory::class),
                $app->make(Clock::class),
            );
        });

        $this->app->singleton(TokenCache::class, fn (): TokenCache => new NullTokenCache);

        $this->app->tag([
            ApiKeyAuthProvider::class,
            BasicAuthProvider::class,
            BearerAuthProvider::class,
        ], 'integration.auth_provider');

        $this->app->singleton(CorrelationIdProvider::class, LaravelContextCorrelationIdProvider::class);

        $this->app->singleton(ExternalApiCaller::class, function (Application $app): ExternalApiCaller {
            $authProviders = [];
            foreach ((array) $app->tagged('integration.auth_provider') as $provider) {
                /** @var AuthProvider $provider */
                $authProviders[] = $provider;
            }

            return new ExternalApiCallerImpl(
                transport: $app->make(HttpTransport::class),
                circuitBreaker: $app->make(CircuitBreaker::class),
                signer: $app->make(RequestSigner::class),
                redactor: $app->make(PayloadRedactor::class),
                clock: $app->make(Clock::class),
                logger: $app->make(LoggerInterface::class),
                authProviders: $authProviders,
                correlationIds: $app->make(CorrelationIdProvider::class),
            );
        });
    }
}
