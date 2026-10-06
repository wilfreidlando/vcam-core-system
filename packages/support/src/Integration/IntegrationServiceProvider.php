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
use Core\Support\Integration\Configuration\ArrayProviderConfigRegistry;
use Core\Support\Integration\Configuration\CircuitBreakerPolicy;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\Provider\ProviderRegistry;
use Core\Support\Integration\Provider\TaggedProviderRegistry;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Core\Support\Integration\Redaction\SensitiveKeySet;
use Core\Support\Integration\Security\OutboundUrlGuard;
use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Support\Integration\Telemetry\OtlpHttpSpanExporter;
use Core\Support\Integration\Transport\GuardedHttpTransport;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Support\Integration\Transport\LaravelHttpTransport;
use Core\Support\Observability\ExchangeJournal;
use Core\Support\Observability\InMemoryExchangeJournal;
use Core\Support\Observability\Tracing\Tracer;
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
                tracer: $app->make(Tracer::class),
            );
        });

        // ADR-0059: one process-wide journal (the singleton ExternalApiCaller
        // holds it), emptied at the start of every queued job — see boot().
        $this->app->singleton(ExchangeJournal::class, fn (): ExchangeJournal => new InMemoryExchangeJournal);

        $this->app->singleton(Tracer::class, fn (): Tracer => new Tracer(
            enabled: is_string(config('observability.tracing.otlp_endpoint')) && config('observability.tracing.otlp_endpoint') !== '',
        ));

        $this->app->singleton(OtlpHttpSpanExporter::class, function (Application $app): OtlpHttpSpanExporter {
            $endpoint = config('observability.tracing.otlp_endpoint');
            $service = config('observability.service_name');
            $timeout = config('observability.tracing.export_timeout_seconds');

            return new OtlpHttpSpanExporter(
                // A bare transport (no tracer): exporting spans must not
                // itself produce spans.
                transport: new LaravelHttpTransport($app->make(HttpFactory::class), $app->make(Clock::class)),
                logger: $app->make(LoggerInterface::class),
                endpoint: is_string($endpoint) ? $endpoint : '',
                serviceName: is_string($service) && $service !== '' ? $service : 'core-system',
                timeoutSeconds: is_numeric($timeout) ? (float) $timeout : 1.0,
            );
        });

        $this->app->singleton(OutboundUrlGuard::class, fn (): OutboundUrlGuard => new OutboundUrlGuard);

        // Audit 2026-10-03 §2.1 — the transport for Project-chosen URLs.
        // Modules opt in per sender with a contextual binding.
        $this->app->bind(GuardedHttpTransport::class, fn (Application $app): GuardedHttpTransport => new GuardedHttpTransport(
            $app->make(HttpTransport::class),
            $app->make(OutboundUrlGuard::class),
            (bool) config('integration.outbound.enforce_at_connect', true),
        ));

        $this->app->singleton(TokenCache::class, fn (): TokenCache => new NullTokenCache);

        $this->app->tag([
            ApiKeyAuthProvider::class,
            BasicAuthProvider::class,
            BearerAuthProvider::class,
        ], 'integration.auth_provider');

        $this->app->singleton(CorrelationIdProvider::class, LaravelContextCorrelationIdProvider::class);

        $this->app->singleton(ExternalApiCaller::class, function (Application $app): ExternalApiCaller {
            $authProviders = [];
            // NOT `(array) $app->tagged(...)` — Container::tagged() returns
            // a Generator; casting an object (rather than iterating it) to
            // array reflects its internal properties, not the tagged
            // instances, silently handing the caller a Closure instead of
            // an AuthProvider. Iterate the Generator directly.
            foreach ($app->tagged('integration.auth_provider') as $provider) {
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
                journal: $app->make(ExchangeJournal::class),
            );
        });

        // Provider registry — config is loaded from a custom loader (or
        // the Laravel config repository as default). Gateways are
        // collected from the 'provider.gateway' tag. The application
        // shell is expected to wire its own config/providers.php
        // (see the canonical example in the repository root).
        $this->app->singleton(ProviderConfigRegistry::class, function (Application $app): ProviderConfigRegistry {
            $raw = $this->loadProvidersConfig($app);

            return new ArrayProviderConfigRegistry($raw);
        });

        $this->app->singleton(ProviderRegistry::class, function (Application $app): ProviderRegistry {
            /** @var iterable<Provider\ProviderGateway> $gateways */
            $gateways = $app->tagged('provider.gateway');

            return new TaggedProviderRegistry(
                gateways: $gateways,
                configs: $app->make(ProviderConfigRegistry::class),
            );
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadProvidersConfig(Application $app): array
    {
        if ($app->bound('providers.config_loader')) {
            $loader = $app->make('providers.config_loader');
            if (is_callable($loader)) {
                $value = $loader($app);
                if (is_array($value)) {
                    /** @var array<string, array<string, mixed>> $value */
                    unset($value['environment']);

                    return $value;
                }
            }
        }

        $config = $app->make('config');
        $value = $config->get('providers', []);
        if (! is_array($value)) {
            return [];
        }
        unset($value['environment']);

        /** @var array<string, array<string, mixed>> $value */
        return $value;
    }
}
