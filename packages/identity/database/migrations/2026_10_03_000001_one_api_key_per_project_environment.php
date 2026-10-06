<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0060 — exactly one API key per (project, environment): one `live`,
 * one `sandbox`. Revoking deletes the key; regenerating replaces it.
 *
 * Existing data (decision D4): revoked keys are deleted, and for each
 * (project, environment) only the most recent active key is kept. Every
 * deleted key is written to the audit trail first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $doomed = DB::select(<<<'SQL'
            SELECT id, project_id, environment, prefix, revoked_at FROM api_keys k
            WHERE k.revoked_at IS NOT NULL
               OR EXISTS (
                   SELECT 1 FROM api_keys newer
                   WHERE newer.project_id = k.project_id
                     AND newer.environment = k.environment
                     AND newer.revoked_at IS NULL
                     AND (newer.created_at, newer.id) > (k.created_at, k.id)
               )
            SQL);

        foreach ($doomed as $raw) {
            /** @var array{id: string, project_id: string, environment: string, prefix: string, revoked_at: string|null} $row */
            $row = (array) $raw;

            if (Schema::hasTable('audit_log')) {
                DB::table('audit_log')->insert([
                    'actor' => 'system:migration',
                    'action' => 'api_key.deleted',
                    'resource' => 'api_key:'.$row['id'],
                    'before' => json_encode(['project_id' => $row['project_id'], 'environment' => $row['environment'], 'prefix' => $row['prefix'], 'revoked_at' => $row['revoked_at']]),
                    'after' => null,
                    'context' => json_encode(['reason' => 'one key per project and environment (ADR-0060)']),
                    'correlation_id' => null,
                ]);
            }

            DB::table('api_keys')->where('id', $row['id'])->delete();
        }

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->timestampTz('last_used_at')->nullable();
            $table->unique(['project_id', 'environment'], 'api_keys_one_per_project_environment');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropUnique('api_keys_one_per_project_environment');
            $table->dropColumn('last_used_at');
        });
    }
};
