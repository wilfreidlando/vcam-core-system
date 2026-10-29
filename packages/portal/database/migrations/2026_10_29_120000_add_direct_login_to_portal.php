<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0075 §2, ADR-0100 — the login by code, off by default.
 *
 * `portal_settings` learns whether a project offers it and from which
 * WhatsApp session of the project the code leaves; `portal_login_codes`
 * holds the codes in flight — only their keyed hash, never the code or the
 * phone. The rules (6 digits, 10 minutes, 5 attempts, one resend a minute)
 * live in the code; the indexes serve the lookups and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->boolean('direct_login_enabled')->default(false);
            $table->string('direct_login_session', 64)->nullable();
        });

        Schema::create('portal_login_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->string('customer_ref', 128);
            // Keyed hash of (project, phone): finds the code without storing the number.
            $table->string('contact_hash', 64);
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('created_by_ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['project_id', 'contact_hash', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_login_codes');

        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->dropColumn(['direct_login_enabled', 'direct_login_session']);
        });
    }
};
