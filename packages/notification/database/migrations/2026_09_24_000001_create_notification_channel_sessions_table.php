<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sessions an operator has registered for a Project (ADR-0057) — the
 * ONLY thing a send checks a client's `variables.session` against. Holds
 * a name and its owner, never any state of the session itself (whether a
 * phone is linked stays the provider's business).
 *
 * `project_id` is nullable on purpose: a session either belongs to one
 * Project (only that Project can send with it) or is global — registered
 * by the operator for the platform, usable by every Project.
 *
 * No FK to `projects` (ADR-0033 §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->text('channel');
            $table->text('session_ref');
            $table->timestampTz('created_at');

            $table->unique(['project_id', 'channel', 'session_ref']);
        });

        // NULLs are distinct in a plain unique index, so two global
        // sessions with the same name would both be accepted without this.
        DB::statement(
            'CREATE UNIQUE INDEX notification_channel_sessions_global_unique '.
            'ON notification_channel_sessions (channel, session_ref) WHERE project_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channel_sessions');
    }
};
