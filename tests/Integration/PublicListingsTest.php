<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;

/**
 * ADR-0088 §2 — `GET /v1/payments`, `/v1/notifications` and `/v1/usage`:
 * opaque cursor, stable order, never another project's rows.
 */
final class PublicListingsTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    private string $projectA;

    private string $projectB;

    #[Test]
    public function payments_are_read_in_full_without_duplicate_or_omission_while_new_ones_arrive(): void
    {
        $headers = $this->keyFor($this->projectA, ['payments:read']);
        $expected = [];

        // Eight payments, three of which share the very same instant: the
        // identifier is the tiebreaker of the order.
        foreach ([0, 1, 2, 2, 2, 3, 4, 5] as $second) {
            $expected[] = $this->payment($this->projectA, $second);
        }
        $this->payment($this->projectB, 1);

        $seen = $this->traverse('/v1/payments', $headers, 3, function (int $page): void {
            if ($page === 1) {
                // Written while the traversal runs: later than everything read.
                $this->payment($this->projectA, 600);
                $this->payment($this->projectA, 601);
            }
        });

        foreach ($expected as $id) {
            self::assertContains($id, $seen, 'a payment older than the start was omitted');
        }
        self::assertSame($seen, array_values(array_unique($seen)), 'a payment came twice');
        self::assertCount(count($seen), array_intersect($seen, $this->idsOf('payments', $this->projectA)), 'only the project\'s own payments');
    }

    #[Test]
    public function payments_can_be_narrowed_by_status_and_period_and_a_bad_cursor_is_a_422(): void
    {
        $headers = $this->keyFor($this->projectA, ['payments:read']);
        $this->payment($this->projectA, 0, 'succeeded');
        $failed = $this->payment($this->projectA, 10, 'failed');
        $this->payment($this->projectA, 20, 'succeeded');

        $this->getJson('/v1/payments?status=failed', $headers)->assertOk()->assertJsonPath('data.0.id', $failed)->assertJsonCount(1, 'data');
        $from = rawurlencode(self::at(5)->format('c'));
        $to = rawurlencode(self::at(15)->format('c'));
        $this->getJson("/v1/payments?created_from={$from}&created_to={$to}", $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_cursor', null);
        $this->getJson('/v1/payments?cursor=not-a-cursor', $headers)->assertStatus(422);
        $this->getJson('/v1/payments?limit=101', $headers)->assertStatus(422);
        $this->getJson('/v1/payments?status=bogus', $headers)->assertStatus(422);
    }

    #[Test]
    public function another_projects_key_sees_no_payment_and_a_key_without_the_scope_is_refused(): void
    {
        $this->payment($this->projectA, 0);
        $this->payment($this->projectA, 1);

        $this->getJson('/v1/payments', $this->keyFor($this->projectB, ['payments:read']))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('next_cursor', null);
        $this->getJson('/v1/payments', $this->keyFor($this->projectA, ['notifications:read']))->assertForbidden();
        $this->getJson('/v1/payments')->assertStatus(401);
    }

    #[Test]
    public function notifications_are_read_in_full_without_duplicate_or_omission_while_new_ones_arrive(): void
    {
        $headers = $this->keyFor($this->projectA, ['notifications:read']);
        $expected = [];

        foreach ([0, 1, 2, 2, 2, 3, 4] as $second) {
            $expected[] = $this->notification($this->projectA, $second);
        }
        $this->notification($this->projectB, 1);

        $seen = $this->traverse('/v1/notifications', $headers, 2, function (int $page): void {
            if ($page === 1) {
                $this->notification($this->projectA, 700);
            }
        });

        foreach ($expected as $id) {
            self::assertContains($id, $seen, 'a notification older than the start was omitted');
        }
        self::assertSame($seen, array_values(array_unique($seen)), 'a notification came twice');
        self::assertCount(count($seen), array_intersect($seen, $this->idsOf('notifications', $this->projectA)));
    }

    #[Test]
    public function notifications_can_be_narrowed_and_never_cross_projects(): void
    {
        $headers = $this->keyFor($this->projectA, ['notifications:read']);
        $this->notification($this->projectA, 0, 'whatsapp', 'sent');
        $failed = $this->notification($this->projectA, 1, 'sms', 'failed');

        $this->getJson('/v1/notifications?channel=sms', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $failed);
        $this->getJson('/v1/notifications?status=failed', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/v1/notifications?status=bogus', $headers)->assertStatus(422);
        $this->getJson('/v1/notifications', $this->keyFor($this->projectB, ['notifications:read']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/v1/notifications', $this->keyFor($this->projectA, ['payments:read']))->assertForbidden();
        // The single-notification route is untouched by the listing.
        $this->getJson("/v1/notifications/{$failed}", $headers)->assertOk()->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function usage_is_read_in_full_without_duplicate_or_omission_while_new_lines_arrive(): void
    {
        $headers = $this->keyFor($this->projectA, ['usage:read']);
        $expected = [];

        foreach ([0, 1, 2, 2, 2, 3, 4] as $second) {
            $expected[] = $this->usage($this->projectA, $second, 'students');
        }
        $this->usage($this->projectB, 1, 'students');

        $seen = $this->traverse('/v1/usage', $headers, 3, function (int $page): void {
            if ($page === 1) {
                $this->usage($this->projectA, 800, 'students');
            }
        });

        foreach ($expected as $id) {
            self::assertContains($id, $seen, 'a usage line older than the start was omitted');
        }
        self::assertSame($seen, array_values(array_unique($seen)), 'a usage line came twice');
        self::assertCount(count($seen), array_intersect($seen, $this->idsOf('usage_records', $this->projectA)));
    }

    #[Test]
    public function usage_can_be_narrowed_and_never_crosses_projects(): void
    {
        $headers = $this->keyFor($this->projectA, ['usage:read']);
        $this->usage($this->projectA, 0, 'students');
        $seats = $this->usage($this->projectA, 10, 'seats');

        $this->getJson('/v1/usage?meter=seats', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $seats)
            ->assertJsonPath('data.0.quantity', 1)->assertJsonPath('data.0.customer_ref', null);
        $this->getJson('/v1/usage?meter=Bad Meter', $headers)->assertStatus(422);
        $this->getJson('/v1/usage?cursor=garbage', $headers)->assertStatus(422);
        $this->getJson('/v1/usage', $this->keyFor($this->projectB, ['usage:read']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/v1/usage', $this->keyFor($this->projectA, ['usage:write']))->assertForbidden();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $a = $this->makeProject('Listing A');
        $this->projectA = $a['project_id'];
        $this->projectB = $this->makeProject('Listing B', $a['organization_id'])['project_id'];
    }

    /**
     * Reads a listing to the end, calling `$between` after each page.
     *
     * @param array<string, string> $headers
     * @return list<string> the ids, in the order they were read
     */
    private function traverse(string $path, array $headers, int $limit, callable $between): array
    {
        $seen = [];
        $cursor = null;
        $page = 0;

        do {
            $query = '?limit='.$limit.($cursor !== null ? '&cursor='.rawurlencode($cursor) : '');
            $response = $this->getJson($path.$query, $headers)->assertOk();
            $page++;

            foreach (self::rows($response->json('data')) as $item) {
                $seen[] = self::text($item['id']);
            }

            $next = $response->json('next_cursor');
            $cursor = $next === null ? null : self::text($next);
            $between($page);
            self::assertLessThan(40, $page, 'the traversal does not end');
        } while ($cursor !== null);

        return $seen;
    }

    /** @return list<string> */
    private function idsOf(string $table, string $projectId): array
    {
        return array_values(array_map(self::text(...), DB::table($table)->where('project_id', $projectId)->pluck('id')->all()));
    }

    private static function at(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T00:00:00Z')->modify("+{$seconds} seconds");
    }

    private function payment(string $projectId, int $second, string $status = 'succeeded'): string
    {
        $id = (string) Str::uuid();
        DB::table('payments')->insert([
            'id' => $id, 'project_id' => $projectId, 'channel' => 'mtn_momo', 'provider_code' => 'p',
            'idempotency_key' => $id, 'provider_transaction_ref' => $id, 'amount_minor' => 1000, 'currency' => 'XAF',
            'status' => $status, 'created_at' => self::at($second), 'updated_at' => self::at($second),
        ]);

        return $id;
    }

    private function notification(string $projectId, int $second, string $channel = 'whatsapp', string $status = 'sent'): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'project_id' => $projectId, 'channel' => $channel, 'to_party_ref' => 'party', 'content_type' => 'text',
            'idempotency_key' => $id, 'status' => $status, 'created_at' => self::at($second), 'updated_at' => self::at($second),
        ]);

        return $id;
    }

    private function usage(string $projectId, int $second, string $meter): string
    {
        $id = (string) Str::uuid();
        DB::table('usage_records')->insert([
            'id' => $id, 'project_id' => $projectId, 'meter' => $meter, 'quantity' => 1, 'idempotency_key' => $id,
            'source' => 'api', 'occurred_at' => self::at($second), 'recorded_at' => self::at($second),
        ]);

        return $id;
    }
}
