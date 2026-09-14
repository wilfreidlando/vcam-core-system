<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The guardrail that guards the guardrails.
 *
 * Its whole purpose is to make dishonesty impossible: a rule may not be
 * declared active while its enforcing artefact is missing, and a rule that
 * cannot yet fail must be publicly listed as such, with an owner and the lot
 * that will activate it.
 *
 * Without this, "we have eleven fitness functions" slowly becomes a comforting
 * story rather than a fact.
 */
#[Group('arch')]
final class GuardCoverageTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private const INACTIVE_REGISTER = 'docs/architecture/garde-fous-inactifs.md';

    #[Test]
    public function every_guard_points_at_an_artefact_that_exists(): void
    {
        foreach (GuardRegistry::GUARDS as $id => $guard) {
            $path = self::ROOT.'/'.$guard['enforced_by'];

            self::assertTrue(
                file_exists($path),
                sprintf(
                    '%s claims to be enforced by %s, which does not exist. '.
                    'Either restore the artefact or stop claiming the rule is covered.',
                    $id,
                    $guard['enforced_by'],
                ),
            );
        }
    }

    #[Test]
    public function every_guard_awaiting_code_is_publicly_registered(): void
    {
        $register = $this->pendingSection();

        foreach (GuardRegistry::GUARDS as $id => $guard) {
            if ($guard['status'] !== GuardRegistry::PENDING_CODE) {
                continue;
            }

            self::assertStringContainsString(
                $id,
                $register,
                sprintf(
                    '%s cannot fail yet but is not listed in %s. '.
                    'An inactive guardrail must be a tracked state, never a silent one.',
                    $id,
                    self::INACTIVE_REGISTER,
                ),
            );

            self::assertArrayHasKey(
                'lot',
                $guard,
                sprintf('%s is pending code, so it must name the delivery lot that activates it.', $id),
            );
        }
    }

    #[Test]
    public function the_register_does_not_list_guards_that_are_already_active(): void
    {
        $register = $this->pendingSection();

        foreach (GuardRegistry::GUARDS as $id => $guard) {
            if ($guard['status'] !== GuardRegistry::ACTIVE) {
                continue;
            }

            self::assertStringNotContainsString(
                '| '.$id.' ',
                $register,
                sprintf(
                    '%s is active but still listed as inactive in %s. Remove the row: '.
                    'a stale register is how "temporarily unenforced" becomes permanent.',
                    $id,
                    self::INACTIVE_REGISTER,
                ),
            );
        }
    }

    /**
     * Only the "en attente de code" section counts as the register. The file
     * also documents which guards ARE active, in the same table format, and
     * that list must not be mistaken for the register itself.
     */
    private function pendingSection(): string
    {
        $registerPath = self::ROOT.'/'.self::INACTIVE_REGISTER;

        self::assertFileExists(
            $registerPath,
            self::INACTIVE_REGISTER.' is missing: the list of not-yet-biting guardrails must be public.'
        );

        $contents = file_get_contents($registerPath);
        self::assertIsString($contents);

        $heading = '## Garde-fous en attente de code';
        $start = strpos($contents, $heading);

        self::assertNotFalse(
            $start,
            sprintf('%s must contain the section "%s".', self::INACTIVE_REGISTER, $heading),
        );

        $rest = substr($contents, $start + strlen($heading));
        $end = strpos($rest, "\n## ");

        return $end === false ? $rest : substr($rest, 0, $end);
    }
}
