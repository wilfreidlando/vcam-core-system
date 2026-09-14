<?php

declare(strict_types=1);

namespace Core\Support;

use Illuminate\Support\ServiceProvider;

/**
 * Shared kernel. Holds cross-cutting primitives only — never business capability.
 * Every bounded context may depend on it; it depends on no bounded context.
 *
 * Global middleware from this package is wired by the application shell
 * (bootstrap/app.php), which is the composition root.
 */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Identifier::class, Uuid7Identifier::class);
    }
}
