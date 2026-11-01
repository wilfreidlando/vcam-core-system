<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Contracts\MfaBypassForbidden;
use Core\Identity\IdentityServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit 2026-10-29 #12 — `IDENTITY_DISABLE_MFA=true` removes the second
 * factor on the web AND API planes. Like `disable_totp_in_local`, it is
 * refused in code outside `APP_ENV=local`, whatever the configuration says.
 */
final class MfaBypassGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonLocalEnvironments(): iterable
    {
        yield 'production' => ['production'];
        yield 'staging' => ['staging'];
        yield 'testing' => ['testing'];
    }

    #[Test]
    #[DataProvider('nonLocalEnvironments')]
    public function the_provider_refuses_to_start_with_mfa_disabled_outside_local(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['identity.disable_mfa' => true]);

        $this->expectException(MfaBypassForbidden::class);

        (new IdentityServiceProvider($this->app))->register();
    }

    #[Test]
    public function the_flag_is_honoured_in_local_only(): void
    {
        $this->app['env'] = 'local';
        config(['identity.disable_mfa' => true]);

        (new IdentityServiceProvider($this->app))->register();

        self::assertTrue((bool) config('identity.disable_mfa'));
    }

    #[Test]
    #[DataProvider('nonLocalEnvironments')]
    public function mfa_enabled_starts_everywhere(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['identity.disable_mfa' => false]);

        (new IdentityServiceProvider($this->app))->register();

        self::assertFalse((bool) config('identity.disable_mfa'));
    }
}
