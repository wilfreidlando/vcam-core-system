<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou G-7 (audit 2026-10-29) — un drapeau qui désactive une protection
 * ne doit jamais être livré actif dans un gabarit d'environnement de
 * production ou de staging (les deux déclarent `APP_ENV=production`), et le
 * jeton du sidecar de confiance ne doit jamais y être vide.
 */
#[Group('arch')]
final class ProductionFlagsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        yield 'production' => ['.env.production.example'];
        yield 'staging' => ['.env.staging.example'];
    }

    #[Test]
    #[DataProvider('templates')]
    public function a_deployment_template_never_ships_a_protection_switched_off(string $template): void
    {
        $values = self::values($template);

        foreach (['IDENTITY_DISABLE_MFA', 'IDENTITY_DISABLE_TOTP_IN_LOCAL', 'PAYMENT_SIMULATOR_ENABLED'] as $flag) {
            self::assertNotContains(
                strtolower($values[$flag] ?? 'false'),
                ['true', '1', 'yes', 'on'],
                sprintf('%s: %s ne doit jamais être actif dans un gabarit de déploiement.', $template, $flag),
            );
        }
    }

    #[Test]
    #[DataProvider('templates')]
    public function a_deployment_template_never_ships_an_empty_trusted_proxy_token(string $template): void
    {
        $values = self::values($template);

        self::assertArrayHasKey('TRUSTED_PROXY_TOKEN', $values, $template.' doit déclarer TRUSTED_PROXY_TOKEN.');
        self::assertNotSame('', $values['TRUSTED_PROXY_TOKEN'], sprintf('%s: TRUSTED_PROXY_TOKEN est vide — l\'application refuse de servir en production sans lui.', $template));
        self::assertStringStartsWith('CHANGE_ME', $values['TRUSTED_PROXY_TOKEN'], $template.' ne doit contenir qu\'un repère, jamais un vrai secret.');
    }

    #[Test]
    #[DataProvider('templates')]
    public function a_deployment_template_runs_in_the_production_environment(string $template): void
    {
        self::assertSame('production', self::values($template)['APP_ENV'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private static function values(string $template): array
    {
        $path = dirname(__DIR__, 2).'/'.$template;
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertNotFalse($lines, $path.' est illisible.');

        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m) === 1) {
                $values[$m[1]] = trim($m[2], '"\'');
            }
        }

        return $values;
    }
}
