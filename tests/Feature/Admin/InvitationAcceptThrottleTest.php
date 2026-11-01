<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0069 §4 — accepting an invitation is public and, for an existing
 * account, checks its current password. The per-invitation limit stops the
 * sender of an invitation from testing that password from many addresses.
 */
final class InvitationAcceptThrottleTest extends TestCase
{
    #[Test]
    public function one_invitation_cannot_be_tried_more_than_five_times_a_minute_whatever_the_address(): void
    {
        RateLimiter::clear('invitation-accept');
        $token = 'guard-'.bin2hex(random_bytes(16));

        $statuses = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
                ->postJson('/v1/admin/organization-invitations/accept', [
                    'token' => $token,
                    'password' => 'a-guessed-password-'.$attempt,
                ])
                ->baseResponse->getStatusCode();
        }

        self::assertNotContains(429, array_slice($statuses, 0, 5), 'The first five attempts must reach the use case.');
        self::assertSame(429, $statuses[5], 'The sixth attempt on the same invitation must be throttled, even from a new address.');
    }

    #[Test]
    public function another_invitation_keeps_its_own_budget(): void
    {
        $first = 'guard-'.bin2hex(random_bytes(16));
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
                ->postJson('/v1/admin/organization-invitations/accept', ['token' => $first, 'password' => 'x-'.$attempt]);
        }

        $status = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/v1/admin/organization-invitations/accept', ['token' => 'guard-'.bin2hex(random_bytes(16)), 'password' => 'y'])
            ->baseResponse->getStatusCode();

        self::assertNotSame(429, $status);
    }
}
