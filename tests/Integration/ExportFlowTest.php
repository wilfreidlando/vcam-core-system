<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Support\Clock;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MutableClock;
use Tests\Support\ProjectsWithKeys;
use Tests\TestCase;
use ZipArchive;

/**
 * ADR-0088 §3 — the export of a project, end to end: asked over HTTP, built
 * by the queued job (the sync queue here), downloaded through a signed link,
 * encrypted at rest, deleted after seven days.
 */
final class ExportFlowTest extends TestCase
{
    use ProjectsWithKeys;
    use RefreshDatabase;

    private MutableClock $clock;

    private string $projectA;

    private string $projectB;

    /** @var array<string, string> */
    private array $a;

    /** @var array<string, string> */
    private array $b;

    #[Test]
    public function an_export_is_built_downloaded_decrypted_and_matches_the_api_shapes_without_another_projects_rows(): void
    {
        $this->seedProject($this->a, 'a', 50_000);
        $this->seedProject($this->b, 'b', 7_000);
        // The ledger stamps its entries with the database clock: the injected clock catches up.
        $this->setClock(new DateTimeImmutable);

        $created = $this->postJson('/v1/exports', [], $this->a + ['Idempotency-Key' => 'export-1'])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('download_url', null);
        $exportId = self::text($created->json('id'));

        // The job ran on the sync queue: the export is done and carries a signed link.
        $status = $this->getJson("/v1/exports/{$exportId}", $this->a)->assertOk()->assertJsonPath('status', 'done');
        $url = self::text($status->json('download_url'));
        self::assertStringContainsString('signature=', $url);
        self::assertNotNull($status->json('download_url_expires_at'));
        self::assertEqualsWithDelta(time() + 900, strtotime(self::text($status->json('download_url_expires_at'))), 5);

        // Encrypted at rest: the stored file is neither a zip nor readable.
        $path = self::text(DB::table('exports')->where('id', $exportId)->value('file_path'));
        $stored = Storage::disk('exports')->get($path);
        self::assertIsString($stored);
        self::assertStringStartsWith('EXP1', $stored);
        self::assertStringNotContainsString('PK'."\x03\x04", $stored);
        self::assertStringNotContainsString('Auto-école Soleil', $stored);

        $files = $this->unzip($this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip'));

        foreach (['manifest.json', 'customers.jsonl', 'plans.jsonl', 'subscriptions.jsonl', 'invoices.jsonl', 'usage.jsonl', 'payments.jsonl', 'notifications.jsonl', 'wallets.jsonl', 'wallet_transactions.jsonl', 'ledger_transactions.jsonl', 'trial_balance.jsonl'] as $name) {
            self::assertArrayHasKey($name, $files);
        }

        // Each line is what the API returns.
        self::assertSame($this->all('/v1/customers'), $this->lines($files['customers.jsonl']));
        self::assertSame($this->all('/v1/payments'), $this->lines($files['payments.jsonl']));
        self::assertSame($this->all('/v1/notifications'), $this->lines($files['notifications.jsonl']));
        self::assertSame($this->all('/v1/usage'), $this->lines($files['usage.jsonl']));
        self::assertCount(1, $this->lines($files['payments.jsonl']));
        self::assertCount(2, $this->lines($files['customers.jsonl']));

        // An erased customer stays erased: no name, no email.
        self::assertStringNotContainsString('Erased Person', $files['customers.jsonl']);
        self::assertStringNotContainsString('erased@example.com', $files['customers.jsonl']);
        self::assertStringContainsString('Auto-école Soleil', $files['customers.jsonl']);

        $wallets = $this->lines($files['wallets.jsonl']);
        $escrow = self::rows($this->getJson('/v1/parties/a-escrow/wallets', $this->a)->assertOk()->json('data'));
        self::assertContains($escrow[0], $wallets);
        $walletId = self::text($escrow[0]['wallet_id']);

        $movements = $this->lines($files['wallet_transactions.jsonl']);
        $api = $this->getJson("/v1/wallets/{$walletId}/transactions?per_page=100", $this->a)->assertOk()->json('data');
        $exported = array_values(array_map(static function (array $row): array {
            unset($row['wallet_id']);

            return $row;
        }, array_filter($movements, static fn (array $row): bool => $row['wallet_id'] === $walletId)));
        self::assertSame($api, $exported);

        // The ledger: transactions, and a trial balance that balances.
        self::assertNotSame([], $this->lines($files['ledger_transactions.jsonl']));
        $net = [];
        foreach ($this->lines($files['trial_balance.jsonl']) as $account) {
            $currency = self::text($account['currency']);
            $net[$currency] = ($net[$currency] ?? 0) + (int) self::text($account['net_debit_minor']);
        }
        self::assertSame(['XAF' => 0], $net);
        $manifest = self::map(json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($this->projectA, $manifest['project_id']);
        self::assertSame(['rows' => 1], self::map(self::map($manifest['files'])['payments.jsonl']));

        // Project B's rows are nowhere in project A's archive.
        $everything = implode("\n", $files);
        foreach (DB::table('payments')->where('project_id', $this->projectB)->pluck('id') as $id) {
            self::assertStringNotContainsString(self::text($id), $everything);
        }
        foreach (DB::table('customers')->where('project_id', $this->projectB)->pluck('id') as $id) {
            self::assertStringNotContainsString(self::text($id), $everything);
        }
        self::assertStringNotContainsString($this->projectB, $everything);
        self::assertStringNotContainsString('Client de B', $everything);

        // The request, the completion and the download are audited.
        $actions = DB::table('audit_log')->where('project_id', $this->projectA)->where('resource', "export:{$exportId}")->pluck('action')->all();
        foreach (['export.requested', 'export.completed', 'export.downloaded'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    #[Test]
    public function the_same_idempotency_key_returns_the_same_export_and_a_key_without_the_scope_is_refused(): void
    {
        $first = $this->postJson('/v1/exports', [], $this->a + ['Idempotency-Key' => 'same-key'])->assertStatus(202);
        $again = $this->postJson('/v1/exports', [], $this->a + ['Idempotency-Key' => 'same-key']);

        self::assertSame($first->json('id'), $again->json('id'));
        self::assertSame(1, DB::table('exports')->where('project_id', $this->projectA)->count());

        $readOnly = $this->keyFor($this->projectA, ['payments:read', 'customers:read'], 'Lecture');
        $this->postJson('/v1/exports', [], $readOnly + ['Idempotency-Key' => 'x'])->assertForbidden();
        $this->getJson('/v1/exports/'.self::text($first->json('id')), $readOnly)->assertForbidden();
        $this->postJson('/v1/exports', [], $this->a)->assertStatus(400);

        // Another project's key does not even see that the export exists.
        $this->getJson('/v1/exports/'.self::text($first->json('id')), $this->b)->assertNotFound();
    }

    #[Test]
    public function the_signed_link_is_the_only_key_and_an_expired_or_altered_link_is_refused(): void
    {
        $id = self::text($this->postJson('/v1/exports', [], $this->a + ['Idempotency-Key' => 'k'])->json('id'));
        $url = self::text($this->getJson("/v1/exports/{$id}", $this->a)->json('download_url'));

        $this->get($url)->assertOk();
        // Unsigned or altered: 403.
        $this->get("/exports/{$id}/download")->assertForbidden();
        $this->get($url.'x')->assertForbidden();
        $this->get(str_replace($id, (string) Str::uuid(), $url))->assertForbidden();

        // Sixteen minutes later the very same link is refused.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->get($url)->assertForbidden();
        Carbon::setTestNow();
    }

    #[Test]
    public function the_purge_deletes_the_file_after_seven_days_keeps_the_record_and_is_idempotent(): void
    {
        $id = self::text($this->postJson('/v1/exports', [], $this->a + ['Idempotency-Key' => 'k'])->json('id'));
        $path = self::text(DB::table('exports')->where('id', $id)->value('file_path'));
        Storage::disk('exports')->assertExists($path);

        // Day 6: nothing to purge.
        $this->moveClock('+6 days');
        self::assertSame(0, Artisan::call('exports:purge-expired'));
        Storage::disk('exports')->assertExists($path);

        // Day 8: the file goes, the record stays.
        $this->moveClock('+2 days');
        self::assertSame(0, Artisan::call('exports:purge-expired'));
        self::assertStringContainsString('1 export file(s) deleted.', Artisan::output());
        Storage::disk('exports')->assertMissing($path);
        self::assertNull(DB::table('exports')->where('id', $id)->value('file_path'));
        self::assertSame('done', DB::table('exports')->where('id', $id)->value('status'));

        // Replayed: nothing more to do.
        self::assertSame(0, Artisan::call('exports:purge-expired'));
        self::assertStringContainsString('0 export file(s) deleted.', Artisan::output());

        // No link any more, and a signed link to the purged export is a 409.
        $this->getJson("/v1/exports/{$id}", $this->a)->assertOk()->assertJsonPath('download_url', null);
        $link = app(\Core\Export\Contracts\SignedDownloadLinks::class)->forExport($id);
        $this->get($link)->assertStatus(409);
        self::assertContains('export.purged', DB::table('audit_log')->where('resource', "export:{$id}")->pluck('action')->all());
    }

    #[Test]
    public function a_failed_build_is_recorded_on_the_export_without_leaking_data(): void
    {
        // The disk refuses the write: the job fails, the export says so.
        config(['filesystems.disks.exports.root' => '/proc/forbidden']);
        $this->app->forgetInstance('filesystem');
        Storage::forgetDisk('exports');

        $id = self::text($this->postJson('/v1/exports', [], $this->a + ['Idempotency-Key' => 'k'])->assertStatus(202)->json('id'));

        $row = DB::table('exports')->where('id', $id)->first();
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertNull($row->file_path);
        self::assertStringStartsWith('Export failed (', self::text($row->error));
        $this->getJson("/v1/exports/{$id}", $this->a)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('download_url', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exports');
        Http::preventStrayRequests();
        config(['payment.simulator.enabled' => true]);

        $this->clock = new MutableClock(new DateTimeImmutable);
        $clock = $this->clock;
        $this->app->bind(Clock::class, static fn (): Clock => $clock);

        $first = $this->makeProject('Export A');
        $this->projectA = $first['project_id'];
        $this->projectB = $this->makeProject('Export B', $first['organization_id'])['project_id'];
        $this->a = $this->keyFor($this->projectA, ApiKeyScopes::ALL);
        $this->b = $this->keyFor($this->projectB, ApiKeyScopes::ALL);
    }

    /**
     * @param array<string, string> $headers
     */
    private function seedProject(array $headers, string $tag, int $amount): void
    {
        $name = $tag === 'a' ? 'Auto-école Soleil' : 'Client de B';
        $this->putJson("/v1/customers/{$tag}-cust", ['display_name' => $name, 'email' => "{$tag}@example.com"], $headers)->assertSuccessful();
        $this->putJson("/v1/customers/{$tag}-erased", ['display_name' => 'Erased Person', 'email' => 'erased@example.com'], $headers)->assertSuccessful();
        $this->postJson("/v1/customers/{$tag}-erased/erase", ['reason' => 'demande'], $headers)->assertSuccessful();

        $payment = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo', 'amount' => $amount, 'currency' => 'XAF', 'reason' => 'Order',
            'wallet_recipient_external_ref' => "{$tag}-escrow",
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('id');
        $payment = self::text($payment);
        $this->postJson("/v1/payments/{$payment}/simulate", ['status' => 'succeeded'], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/v1/wallets/transfers', [
            'currency' => 'XAF',
            'legs' => [['from' => "{$tag}-escrow", 'to' => "{$tag}-merchant", 'amount' => 1_000, 'to_party_type' => 'merchant']],
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);

        $projectId = $tag === 'a' ? $this->projectA : $this->projectB;
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $projectId, 'channel' => 'whatsapp', 'to_party_ref' => 'p', 'content_type' => 'text',
            'idempotency_key' => (string) Str::uuid(), 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $projectId, 'meter' => 'students', 'quantity' => 2,
            'idempotency_key' => (string) Str::uuid(), 'source' => 'api', 'occurred_at' => now(), 'recorded_at' => now(),
        ]);
    }

    /**
     * Every item of an API listing, read page after page with project A's key.
     *
     * @return list<array<string, mixed>>
     */
    private function all(string $path): array
    {
        $items = [];
        $cursor = null;

        do {
            $response = $this->getJson($path.'?limit=100'.($cursor !== null ? '&cursor='.rawurlencode($cursor) : ''), $this->a)->assertOk();
            array_push($items, ...self::rows($response->json('data')));
            $next = $response->json('next_cursor');
            $cursor = $next === null ? null : self::text($next);
        } while ($cursor !== null);

        return $items;
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @return array<string, string> file name => contents
     */
    private function unzip(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export-test');
        self::assertIsString($path);
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        self::assertTrue($zip->open($path));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(string $jsonl): array
    {
        return array_values(array_map(
            static fn (string $line): array => self::map(json_decode($line, true, flags: JSON_THROW_ON_ERROR)),
            array_filter(explode("\n", $jsonl), static fn (string $line): bool => $line !== ''),
        ));
    }

    private function moveClock(string $modifier): void
    {
        $this->setClock($this->clock->moment->modify($modifier));
    }

    private function setClock(DateTimeImmutable $moment): void
    {
        $this->clock->moment = $moment;
    }
}
