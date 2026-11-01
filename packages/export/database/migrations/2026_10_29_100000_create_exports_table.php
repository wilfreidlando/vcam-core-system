<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0088 §3 — the exports of a project. A row is the **record** of an
 * export (who asked, when, how it ended); the file it points to is
 * encrypted at rest and deleted after seven days, the row stays.
 *
 * Status vocabulary: `queued`, `running`, `done`, `failed` — kept by the
 * application, not by the database. Indexes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('status', 16);
            $table->string('requested_by', 128);
            $table->timestampTz('created_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('file_path', 512)->nullable();
            $table->text('error')->nullable();

            // The listing of a project's exports, newest first.
            $table->index(['project_id', 'created_at']);
            // The daily purge: the files whose retention is over.
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
