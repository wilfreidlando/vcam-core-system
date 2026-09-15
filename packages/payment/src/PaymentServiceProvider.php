<?php

declare(strict_types=1);

namespace Core\Payment;

use Core\Payment\Application\UseCase\GetPaymentStatusUseCase;
use Core\Payment\Application\UseCase\HandlePaymentStatusChangedUseCase;
use Core\Payment\Application\UseCase\ListPaymentsUseCase;
use Core\Payment\Application\UseCase\ReconcileStuckPaymentsUseCase;
use Core\Payment\Application\UseCase\StartPaymentUseCase;
use Core\Payment\Contracts\GetPaymentStatus;
use Core\Payment\Contracts\ListPayments;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Payment\Infrastructure\Persistence\Eloquent\EloquentPaymentRepository;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayAdapter;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayStatusMapper;
use Core\Payment\Infrastructure\Provider\PaymentGatewayRegistryImpl;
use Core\Payment\Infrastructure\Routing\ConfigPaymentProviderRouter;
use Core\Support\Clock;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        Route::middleware('api')->group(__DIR__.'/../routes/api.php');
    }

    public function register(): void
    {
        $this->app->singleton(MyCoolPayStatusMapper::class, function (Application $app): MyCoolPayStatusMapper {
            $configs = $app->make(\Core\Support\Integration\Configuration\ProviderConfigRegistry::class);
            $code = $configs->configFor(
                ProviderCode::fromString('my-coolpay'),
            )->code;

            return new MyCoolPayStatusMapper($code);
        });

        $this->app->singleton(MyCoolPayAdapter::class, function (Application $app): MyCoolPayAdapter {
            $configs = $app->make(\Core\Support\Integration\Configuration\ProviderConfigRegistry::class);
            $code = ProviderCode::fromString('my-coolpay');
            $config = $configs->configFor($code);

            return new MyCoolPayAdapter(
                caller: $app->make(\Core\Support\Integration\ExternalApiCaller::class),
                config: $config,
                statusMapper: $app->make(MyCoolPayStatusMapper::class),
            );
        });

        $this->app->tag([MyCoolPayAdapter::class], 'provider.gateway');

        // Typed registry — see ADR-0037. Use cases in the payment module
        // depend on PaymentGatewayRegistry (the typed version) rather than
        // the generic ProviderRegistry, so they get back a
        // PaymentGateway instead of the base ProviderGateway.
        $this->app->singleton(PaymentGatewayRegistry::class, function (Application $app): PaymentGatewayRegistry {
            return new PaymentGatewayRegistryImpl(
                $app->make(ProviderRegistry::class),
            );
        });

        $this->app->singleton(PaymentProviderRouter::class, function (Application $app): PaymentProviderRouter {
            /** @var array<string, string> $defaults */
            $defaults = $app->make('config')->get('payment_channels.default', []);
            /** @var array<string, array<string, string>> $overrides */
            $overrides = $app->make('config')->get('payment_channels.project_overrides', []);

            return new ConfigPaymentProviderRouter($defaults, $overrides);
        });

        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);

        $this->app->bind(StartPayment::class, StartPaymentUseCase::class);
        $this->app->bind(GetPaymentStatus::class, GetPaymentStatusUseCase::class);
        $this->app->bind(PaymentStatusChangeHandler::class, HandlePaymentStatusChangedUseCase::class);
        $this->app->bind(ListPayments::class, ListPaymentsUseCase::class);

        $this->app->bind(ReconcileStuckPayments::class, function (Application $app): ReconcileStuckPaymentsUseCase {
            /** @var int $stuckAfterMinutes */
            $stuckAfterMinutes = $app->make('config')->get('payment.reconciliation.stuck_after_minutes', 15);
            /** @var int $batchSize */
            $batchSize = $app->make('config')->get('payment.reconciliation.batch_size', 100);

            return new ReconcileStuckPaymentsUseCase(
                payments: $app->make(PaymentRepository::class),
                gateways: $app->make(PaymentGatewayRegistry::class),
                statusChangeHandler: $app->make(PaymentStatusChangeHandler::class),
                clock: $app->make(Clock::class),
                logger: $app->make(\Psr\Log\LoggerInterface::class),
                stuckAfterMinutes: $stuckAfterMinutes,
                batchSize: $batchSize,
            );
        });
    }
}
