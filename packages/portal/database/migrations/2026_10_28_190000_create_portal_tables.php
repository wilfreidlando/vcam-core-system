<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0075 — the only two tables the portal owns.
 *
 * `portal_settings` is the look and the permissions a project chose;
 * `portal_sessions` holds the one-time links and the cookie sessions they
 * become. Both secrets are stored as keyed hashes only. The rules (formats,
 * lifetimes, who may do what) live in the code; the unique keys are the
 * concurrency primitive of the single-use link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_settings', function (Blueprint $table): void {
            $table->uuid('project_id')->primary();
            $table->string('display_name', 80);
            $table->string('logo_url', 500)->nullable();
            $table->string('primary_color', 7)->default('#2563eb');
            $table->string('support_url', 500)->nullable();
            $table->string('locale', 5)->default('fr');
            $table->json('allowed_return_urls')->nullable();
            $table->boolean('allow_self_cancel')->default(false);
            $table->boolean('allow_plan_change')->default(false);
            $table->json('payment_channels')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('portal_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->string('customer_ref', 128);
            $table->string('flow', 32);
            $table->string('flow_ref', 64)->nullable();
            $table->string('return_url', 2048)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('token_expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('session_hash', 64)->nullable()->unique();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('session_expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('created_by_ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['project_id', 'customer_id']);
            // The purge of the closed sessions reads these.
            $table->index('session_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('portal_settings');
    }
};
