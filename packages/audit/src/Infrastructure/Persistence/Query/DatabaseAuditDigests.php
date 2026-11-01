<?php

declare(strict_types=1);

namespace Core\Audit\Infrastructure\Persistence\Query;

use Core\Audit\Contracts\AuditDigests;
use Core\Audit\Contracts\AuditDigestView;
use Core\Audit\Contracts\AuditVerificationResult;
use Core\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0081 S-16 — seals and verifies the audit log's daily digest chain.
 */
final readonly class DatabaseAuditDigests implements AuditDigests
{
    /** A run never seals more than this many days (first run on an old log). */
    private const MAX_DAYS_PER_RUN = 400;

    public function __construct(private Clock $clock) {}

    public function sealThrough(DateTimeImmutable $upTo): array
    {
        $utc = new DateTimeZone('UTC');
        $today = $this->clock->now()->setTimezone($utc)->setTime(0, 0);
        $last = $upTo->setTimezone($utc)->setTime(0, 0);
        if ($last >= $today) {
            // Only complete days are sealed: today is still being written.
            $last = $today->modify('-1 day');
        }

        /** @var list<AuditDigestView> $sealed */
        $sealed = [];

        DB::transaction(function () use ($last, $utc, &$sealed): void {
            // One sealer at a time (two schedulers, a manual run).
            DB::statement('LOCK TABLE audit_log_digests IN EXCLUSIVE MODE');

            $latest = DB::table('audit_log_digests')->orderByDesc('day')->first(['day', 'digest']);
            if ($latest !== null) {
                $day = (new DateTimeImmutable(self::str($latest, 'day'), $utc))->modify('+1 day');
                $previous = self::str($latest, 'digest');
            } else {
                $first = DB::table('audit_log')->min('created_at');
                if (! is_string($first)) {
                    return;
                }
                $day = (new DateTimeImmutable($first))->setTimezone($utc)->setTime(0, 0);
                $previous = null;
            }

            for ($i = 0; $day <= $last && $i < self::MAX_DAYS_PER_RUN; $i++, $day = $day->modify('+1 day')) {
                [$count, $firstId, $lastId, $digest] = $this->digestOf($day, $previous);
                DB::table('audit_log_digests')->insert([
                    'day' => $day->format('Y-m-d'),
                    'entries_count' => $count,
                    'first_entry_id' => $firstId,
                    'last_entry_id' => $lastId,
                    'previous_digest' => $previous,
                    'digest' => $digest,
                ]);
                $sealed[] = new AuditDigestView($day->format('Y-m-d'), $count, $previous, $digest);
                $previous = $digest;
            }
        });

        return $sealed;
    }

    public function verify(): AuditVerificationResult
    {
        $utc = new DateTimeZone('UTC');
        $broken = [];
        $checked = 0;
        $expectedPrevious = null;

        foreach (DB::table('audit_log_digests')->orderBy('day')->cursor() as $row) {
            $checked++;
            $day = self::str($row, 'day');
            $storedPrevious = self::optStr($row, 'previous_digest');
            [, , , $recomputed] = $this->digestOf(new DateTimeImmutable($day, $utc), $storedPrevious);

            if ($recomputed !== self::str($row, 'digest') || $storedPrevious !== $expectedPrevious) {
                $broken[] = $day;
            }
            $expectedPrevious = self::str($row, 'digest');
        }

        return new AuditVerificationResult($checked, $broken);
    }

    /**
     * @return array{0: int, 1: int|null, 2: int|null, 3: string}
     */
    private function digestOf(DateTimeImmutable $day, ?string $previous): array
    {
        $context = hash_init('sha256');
        hash_update($context, ($previous ?? '').'|'.$day->format('Y-m-d'));

        $count = 0;
        $firstId = null;
        $lastId = null;
        $rows = DB::table('audit_log')
            ->where('created_at', '>=', $day->format('Y-m-d').' 00:00:00+00')
            ->where('created_at', '<', $day->modify('+1 day')->format('Y-m-d').' 00:00:00+00')
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $data = (array) $row;
            $id = is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0;
            $firstId ??= $id;
            $lastId = $id;
            $count++;
            $fields = [
                $id,
                $data['actor'] ?? null,
                $data['action'] ?? null,
                $data['resource'] ?? null,
                $data['before'] ?? null,
                $data['after'] ?? null,
                $data['context'] ?? null,
                $data['correlation_id'] ?? null,
                $data['created_at'] ?? null,
            ];

            // ADR-0069 §2 — the tenant attribution decides who may read the
            // entry, so it is sealed too: re-attributing an entry to another
            // organization breaks the chain. Only when set — an entry written
            // before the attribution existed hashes exactly as it was sealed.
            $organizationId = $data['organization_id'] ?? null;
            $projectId = $data['project_id'] ?? null;
            if ($organizationId !== null || $projectId !== null) {
                $fields[] = $organizationId;
                $fields[] = $projectId;
            }

            hash_update($context, "\n".json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return [$count, $firstId, $lastId, hash_final($context)];
    }

    private static function str(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function optStr(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
