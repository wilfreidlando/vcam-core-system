<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0078 §5 — the register of the reconciliation runs: "on doit pouvoir
 * prouver que le contrôle a tourné et qu'il a passé".
 *
 * One row per run, per project (a reconciliation is always scoped to a
 * project — ADR-0069 §2). `provider_code` is `NULL` for level A (the
 * Core's own invariants): an invariant check is not provider-specific.
 *
 * `stats` is JSON, not columns: the counters differ per level, and
 * ADR-0083 §4 forbids anything personal in there — only counters and
 * identifiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('provider_code')->nullable();
            $table->text('level');
            $table->timestampTz('window_start')->nullable();
            $table->timestampTz('window_end')->nullable();
            $table->text('status');
            $table->jsonb('stats')->default('{}');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();

            $table->index(['project_id', 'level', 'started_at']);
            $table->index(['level', 'status', 'started_at']);
        });

        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_level_check CHECK (level IN ('invariants', 'transactions', 'balance'))");
        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN ('running', 'completed', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
