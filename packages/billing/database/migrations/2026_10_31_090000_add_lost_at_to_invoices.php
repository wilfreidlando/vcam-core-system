<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0094 §12 — the write-off is a human act: the invoice stops being a
 * receivable. Nothing but `updated_at` kept the instant of that act, and
 * `updated_at` moves again on any later touch — the receivable screen
 * (ADR-0073) needs a date it can trust.
 *
 * `lost_at` is that date. It is nullable: a live receivable has none.
 *
 * The column is declared with the Schema builder only, and it carries an
 * index because the screen reads the write-offs of a project by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestampTz('lost_at')->nullable();
            $table->index('lost_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['lost_at']);
            $table->dropColumn('lost_at');
        });
    }
};
