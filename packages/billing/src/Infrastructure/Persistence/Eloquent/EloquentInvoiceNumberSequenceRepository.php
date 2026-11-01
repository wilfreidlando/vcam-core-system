<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Port\InvoiceNumberSequenceRepository;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0094 §5 — the numbering of the invoices of a project.
 *
 * The counter is a **table**, not a PostgreSQL sequence: a sequence
 * leaves a hole at every rollback, and a hole in the legal series of a
 * project is a hole the project cannot explain (ADR-0073
 * §Alternatives).
 *
 * The row is locked (`FOR UPDATE`) and the counter moves on in the same
 * transaction: two simultaneous issuances take two different numbers,
 * and neither of them leaves a gap.
 */
final readonly class EloquentInvoiceNumberSequenceRepository implements InvoiceNumberSequenceRepository
{
    public function nextNumber(string $projectId, int $year): int
    {
        // The row of the (project, year) pair is created on the first
        // issuance of the year. `insertOrIgnore` is race-safe: the
        // unique constraint of the primary key decides, not the read
        // that precedes it.
        DB::table('invoice_number_sequences')->insertOrIgnore([
            'project_id' => $projectId,
            'year' => $year,
            'next_value' => 1,
            'updated_at' => now(),
        ]);

        $row = DB::table('invoice_number_sequences')
            ->where('project_id', $projectId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            // The row was created and rolled back by another
            // transaction between the insert and the lock: the sequence
            // starts over, and the caller will retry.
            return 1;
        }

        $next = is_numeric($row->next_value) ? (int) $row->next_value : 1;

        DB::table('invoice_number_sequences')
            ->where('project_id', $projectId)
            ->where('year', $year)
            ->update([
                'next_value' => $next + 1,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
