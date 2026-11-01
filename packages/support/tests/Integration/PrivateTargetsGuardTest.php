<?php

declare(strict_types=1);

namespace Core\Support\Tests\Integration;

use Core\Support\Integration\IntegrationServiceProvider;
use Core\Support\Integration\Security\OutboundUrlGuard;
use Core\Support\Integration\Security\PrivateTargetsForbidden;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0115 — `OUTBOUND_ALLOW_PRIVATE_TARGETS=true` opens the private
 * ranges (RFC 1918, unique-local IPv6) so a local Core can call the
 * client application it shares a Docker network with.
 *
 * The refusal is in **code**, at startup: no environment variable alone
 * can open the private ranges on a deployment that faces the internet —
 * the same rule as the MFA bypass (`MfaBypassForbidden`).
 */
final class PrivateTargetsGuardTest extends TestCase
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
    public function the_provider_refuses_to_start_with_private_targets_outside_local(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['integration.outbound.allow_private_targets' => true]);

        $this->expectException(PrivateTargetsForbidden::class);

        (new IntegrationServiceProvider($this->app))->register();
    }

    #[Test]
    public function the_flag_is_honoured_in_local_only(): void
    {
        $this->app['env'] = 'local';
        config(['integration.outbound.allow_private_targets' => true]);

        (new IntegrationServiceProvider($this->app))->register();

        self::assertTrue((bool) config('integration.outbound.allow_private_targets'));
        self::assertTrue($this->app->make(OutboundUrlGuard::class)->allowsPrivateTargets());
    }

    #[Test]
    #[DataProvider('nonLocalEnvironments')]
    public function private_targets_stay_refused_everywhere_by_default(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['integration.outbound.allow_private_targets' => false]);

        (new IntegrationServiceProvider($this->app))->register();

        self::assertFalse($this->app->make(OutboundUrlGuard::class)->allowsPrivateTargets());
    }
}
