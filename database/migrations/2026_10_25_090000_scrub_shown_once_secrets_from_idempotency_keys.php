<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0081 S-07 / S-08 — the idempotency store kept the full response of a
 * payment creation, `callback_secret` included: in clear, and handed out
 * again on every replay of the same `Idempotency-Key`.
 *
 * The middleware no longer stores a secret a route marks as shown once
 * (`Core\Support\Http\ShownOnce`). This migration removes the copies stored
 * before that fix. One-way on purpose: there is nothing to restore — a
 * secret that leaves the store must not come back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "UPDATE idempotency_keys SET response_body = response_body - 'callback_secret' ".
            "WHERE response_body IS NOT NULL AND jsonb_exists(response_body, 'callback_secret')"
        );
    }

    public function down(): void
    {
        // Nothing to undo: the removed secrets must never be stored again.
    }
};
