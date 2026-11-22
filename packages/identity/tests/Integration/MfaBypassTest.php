<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\IdentityServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit 2026-10-29 #12 — `IDENTITY_DISABLE_MFA=true` removes the second
 * factor on the web AND API planes.
 *
 * ADR-0128 — **one variable, everywhere**: the flag is the decision. It is
 * honoured on the developer's machine, on the staging deployment and on the
 * live one; no runtime refuses to start because of it. What remains in
 * exchange is the trace: every login without MFA is recorded in the audit
 * log as `operator.mfa_skipped` (see `tests/Feature/Admin/MfaDisabledLoginTest.php`).
 */
final class MfaBypassTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function runtimes(): iterable
    {
        yield 'local' => ['local'];
        // The staging deployment and the live one run the same code, in the
        // same `APP_ENV=production` (ADR-0064): the environment does not
        // distinguish them, and it does not have to any more.
        yield 'deployed' => ['production'];
        yield 'testing' => ['testing'];
    }

    #[Test]
    #[DataProvider('runtimes')]
    public function the_application_starts_with_mfa_disabled_on_every_runtime(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['identity.disable_mfa' => true]);

        (new IdentityServiceProvider($this->app))->register();

        self::assertTrue((bool) config('identity.disable_mfa'));
    }

    #[Test]
    #[DataProvider('runtimes')]
    public function mfa_enabled_starts_everywhere(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['identity.disable_mfa' => false]);

        (new IdentityServiceProvider($this->app))->register();

        self::assertFalse((bool) config('identity.disable_mfa'));
    }
}
