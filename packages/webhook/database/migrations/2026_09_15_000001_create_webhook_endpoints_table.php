<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0040 §5: `secret_encrypted` is `Crypt`-encrypted at rest, never
 * hashed — it must be read back in plaintext to sign each delivery.
 *
 * No FK to `projects` (ADR-0033 §5 — no cross-module database join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('url');
            $table->text('secret_encrypted');
            $table->jsonb('events');
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
