<?php

declare(strict_types=1);

namespace Tests\Architecture;

use BackedEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fitness function 6b — the canonical status vocabulary is fixed.
 *
 * These values are part of the public contract: consuming products branch on
 * them, webhooks carry them, dashboards display them. Adding a case is a
 * breaking change for every consumer that switches exhaustively; renaming one
 * silently breaks their logic.
 *
 * So the enum may not drift from the governance vocabulary without a deliberate
 * contract decision — which means an ADR, an API version consideration, and
 * updating this list on purpose.
 */
#[Group('arch')]
final class CanonicalStatusTest extends TestCase
{
    private const PAYMENT_STATUS_ENUM = 'Core\Payment\Domain\Model\PaymentStatus';

    private const NOTIFICATION_STATUS_ENUM = 'Core\Notification\Domain\Model\NotificationStatus';

    /**
     * Spec technique §9.4.
     *
     * @var list<string>
     */
    private const PAYMENT_STATUSES = [
        'pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded',
    ];

    /**
     * @var list<string>
     */
    private const NOTIFICATION_STATUSES = [
        'queued', 'sent', 'delivered', 'read', 'failed',
    ];

    #[Test]
    public function payment_statuses_match_the_canonical_vocabulary(): void
    {
        self::assertEnumMatches(self::PAYMENT_STATUS_ENUM, self::PAYMENT_STATUSES, 'Lot 3');
    }

    #[Test]
    public function notification_statuses_match_the_canonical_vocabulary(): void
    {
        self::assertEnumMatches(self::NOTIFICATION_STATUS_ENUM, self::NOTIFICATION_STATUSES, 'Lot 5');
    }

    /**
     * @param list<string> $expected
     */
    private function assertEnumMatches(string $enum, array $expected, string $lot): void
    {
        if (! enum_exists($enum)) {
            self::markTestSkipped(sprintf(
                '%s does not exist yet (%s). Registered as G6b in docs/architecture/garde-fous-inactifs.md.',
                $enum,
                $lot,
            ));
        }

        /** @var list<BackedEnum> $cases */
        $cases = $enum::cases();

        $actual = array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $cases,
        );

        sort($actual);
        $sortedExpected = $expected;
        sort($sortedExpected);

        self::assertSame(
            $sortedExpected,
            $actual,
            sprintf(
                "%s has drifted from the canonical vocabulary.\n".
                "Expected: %s\nFound:    %s\n\n".
                'These values are part of the public API contract: consumers branch on them. '.
                'Changing the set needs an ADR and an API version review, not an edit.',
                $enum,
                implode(', ', $sortedExpected),
                implode(', ', $actual),
            ),
        );
    }
}
