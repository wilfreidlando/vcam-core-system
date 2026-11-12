<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Unit\Domain;

use Core\Billing\Domain\Exception\InvalidTenantSchema;
use Core\Billing\Domain\Model\TenantSchemaPattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0122 §6 — le motif que doit respecter la référence du Core.
 *
 * Le motif décrit un **sous-ensemble** de l'alphabet du Core
 * (`^[A-Za-z0-9._:-]{1,128}$`) : il ne peut jamais autoriser une référence
 * que le Core refuserait. Pour le garantir, sa syntaxe est restreinte — et
 * les quantificateurs sont lus comme des **blocs** : leur virgule est une
 * borne de répétition, pas un caractère de référence.
 *
 * Ces tests sont du PHP pur : ni base, ni framework.
 */
final class TenantSchemaPatternTest extends TestCase
{
    #[Test]
    public function a_repetition_quantifier_is_accepted(): void
    {
        // « AE suivi de 4 à 8 chiffres » : la virgule est la borne de la
        // répétition, elle ne décrit aucun caractère hors de l'alphabet.
        $pattern = TenantSchemaPattern::of('AE[0-9]{4,8}');

        self::assertSame('AE[0-9]{4,8}', $pattern->pattern);
        self::assertTrue($pattern->matches('AE1234'));
        self::assertTrue($pattern->matches('AE12345678'));
        self::assertFalse($pattern->matches('AE123'));
        self::assertFalse($pattern->matches('AE123456789'));
    }

    #[Test]
    public function an_exact_or_open_quantifier_is_accepted(): void
    {
        self::assertTrue(TenantSchemaPattern::of('AE[0-9]{4}')->matches('AE1234'));
        self::assertTrue(TenantSchemaPattern::of('AE[0-9]{4,}')->matches('AE123456789'));
    }

    #[Test]
    public function a_malformed_quantifier_is_refused(): void
    {
        foreach (['AE[0-9]{', 'AE[0-9]{}', 'AE[0-9]{,8}', 'AE[0-9]{4,8'] as $malformed) {
            try {
                TenantSchemaPattern::of($malformed);
                self::fail(sprintf('le motif "%s" aurait dû être refusé.', $malformed));
            } catch (InvalidTenantSchema $exception) {
                self::assertStringContainsString('quantificateur', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function the_pattern_describes_core_references_only(): void
    {
        // Le joker matcherait n'importe quoi.
        $this->assertRefused('AE.1', 'joker');

        // Une classe négative décrit des caractères hors de l'alphabet.
        $this->assertRefused('AE[^0-9]', 'négative');

        // Un échappement large autoriserait un caractère hors de l'alphabet.
        $this->assertRefused('AE\d', 'échappement');

        // Un caractère hors de l'alphabet du Core.
        $this->assertRefused('AE 1', 'alphabet');
    }

    #[Test]
    public function the_pattern_is_anchored_on_the_whole_reference(): void
    {
        $pattern = TenantSchemaPattern::of('code-');

        self::assertTrue($pattern->matches('code-'));
        self::assertFalse($pattern->matches('code-42'));
        self::assertFalse($pattern->matches('le-code-'));
    }

    #[Test]
    public function an_empty_or_too_long_pattern_is_refused(): void
    {
        $this->assertRefused('   ', 'vide');
        $this->assertRefused(str_repeat('A', 300), 'dépasse');
    }

    private function assertRefused(string $pattern, string $reason): void
    {
        try {
            TenantSchemaPattern::of($pattern);
            self::fail(sprintf('le motif "%s" aurait dû être refusé (%s).', $pattern, $reason));
        } catch (InvalidTenantSchema $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
