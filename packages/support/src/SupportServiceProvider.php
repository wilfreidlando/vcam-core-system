<?php

declare(strict_types=1);

namespace Core\Support;

use Core\Support\Privacy\BlindIndex;
use Core\Support\Privacy\EncrypterPersonalDataCipher;
use Core\Support\Privacy\HmacBlindIndex;
use Core\Support\Privacy\PersonalDataCipher;
use Core\Support\Privacy\Presentation\Console\Commands\GeneratePrivacyKeyCommand;
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

        // ADR-0083 §2 — encryption and blind index of personal data. The
        // keys come from the environment; a deployment without them fails
        // on first use, never silently in clear.
        $this->app->bind(PersonalDataCipher::class, EncrypterPersonalDataCipher::class);
        $this->app->bind(BlindIndex::class, HmacBlindIndex::class);
        $this->app->bind(EncrypterPersonalDataCipher::class, fn (): EncrypterPersonalDataCipher => new EncrypterPersonalDataCipher(
            self::configString('privacy.encryption_keys'),
        ));
        $this->app->bind(HmacBlindIndex::class, fn (): HmacBlindIndex => new HmacBlindIndex(
            self::configString('privacy.blind_index_key'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GeneratePrivacyKeyCommand::class]);
        }
    }

    private static function configString(string $key): string
    {
        $value = config($key, '');

        return is_string($value) ? $value : '';
    }
}
