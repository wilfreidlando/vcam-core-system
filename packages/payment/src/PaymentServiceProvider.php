<?php

declare(strict_types=1);

namespace Core\Payment;

use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayAdapter;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayStatusMapper;
use Core\Support\Integration\Provider\ProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the payment module into the Laravel container.
 *
 * Lot 0 + Lot 3 first slice:
 *   - registers the canonical `PaymentStatus` enum (pure, self-loading)
 *   - loads the inbound webhook route (the only HTTP surface for now)
 *   - binds and tags the MyCoolPayAdapter so the ProviderRegistry can
 *     find it under the 'my-coolpay' code
 *
 * The MyCoolPayAdapter is built by reading the loaded `ProviderConfig`
 * (from `config/providers.php`) and looking up the bound
 * `ProviderConfigRegistry`. This is the canonical wiring pattern that
 * any future payment adapter will follow — add a `register()` block,
 * declare the class with its `providerCode()`, and tag it.
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }

    public function register(): void
    {
        $this->app->singleton(MyCoolPayStatusMapper::class, function (Application $app): MyCoolPayStatusMapper {
            $configs = $app->make(\Core\Support\Integration\Configuration\ProviderConfigRegistry::class);
            $code = $configs->configFor(
                \Core\Support\Integration\Provider\ProviderCode::fromString('my-coolpay'),
            )->code;

            return new MyCoolPayStatusMapper($code);
        });

        $this->app->singleton(MyCoolPayAdapter::class, function (Application $app): MyCoolPayAdapter {
            $configs = $app->make(\Core\Support\Integration\Configuration\ProviderConfigRegistry::class);
            $code = \Core\Support\Integration\Provider\ProviderCode::fromString('my-coolpay');
            $config = $configs->configFor($code);

            return new MyCoolPayAdapter(
                caller: $app->make(\Core\Support\Integration\ExternalApiCaller::class),
                config: $config,
                statusMapper: $app->make(MyCoolPayStatusMapper::class),
            );
        });

        $this->app->tag([MyCoolPayAdapter::class], 'provider.gateway');
    }
}
