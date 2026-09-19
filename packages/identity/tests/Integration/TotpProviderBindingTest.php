<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Domain\Port\TotpProvider;
use Core\Identity\Infrastructure\Security\AlwaysValidTotpProvider;
use Core\Identity\Infrastructure\Security\Google2faTotpProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `IdentityServiceProvider` swaps `TotpProvider` for a dev-only
 * always-accepts implementation, but only when APP_ENV=local **and**
 * `identity.disable_totp_in_local` is true — both are checked in code,
 * not just the config flag, precisely so this can never activate outside
 * a local environment (see IdentityServiceProvider::register()).
 */
final class TotpProviderBindingTest extends TestCase
{
    #[Test]
    public function the_real_totp_provider_is_used_outside_local_even_with_the_flag_on(): void
    {
        // The test environment is 'testing', never 'local' — setting the
        // flag alone must not be enough to bypass real MFA verification.
        config(['identity.disable_totp_in_local' => true]);

        self::assertInstanceOf(Google2faTotpProvider::class, $this->app->make(TotpProvider::class));
    }

    #[Test]
    public function the_real_totp_provider_is_used_in_local_without_the_flag(): void
    {
        $this->app['env'] = 'local';
        config(['identity.disable_totp_in_local' => false]);

        self::assertInstanceOf(Google2faTotpProvider::class, $this->app->make(TotpProvider::class));
    }

    #[Test]
    public function the_dev_bypass_activates_only_with_both_local_and_the_flag(): void
    {
        $this->app['env'] = 'local';
        config(['identity.disable_totp_in_local' => true]);

        self::assertInstanceOf(AlwaysValidTotpProvider::class, $this->app->make(TotpProvider::class));
    }
}
