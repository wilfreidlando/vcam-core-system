<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0066 — the Core has a single environment: testing happens on the
 * staging deployment, never with a second key. Sandbox keys are deleted
 * (each one written to the audit trail first, like ADR-0060's own cleanup);
 * the remaining live key becomes the project's only key. A project that had
 * only a sandbox key ends up with none: the operator generates one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('api_keys')->where('environment', 'sandbox')->get(['id', 'project_id', 'prefix']) as $raw) {
            /** @var array{id: string, project_id: string, prefix: string} $row */
            $row = (array) $raw;

            if (Schema::hasTable('audit_log')) {
                DB::table('audit_log')->insert([
                    'actor' => 'system:migration',
                    'action' => 'api_key.deleted',
                    'resource' => 'api_key:'.$row['id'],
                    'before' => json_encode(['project_id' => $row['project_id'], 'prefix' => $row['prefix']]),
                    'after' => null,
                    'context' => json_encode(['reason' => 'single environment, no sandbox key (ADR-0066)']),
                    'correlation_id' => null,
                ]);
            }

            DB::table('api_keys')->where('id', $row['id'])->delete();
        }

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropUnique('api_keys_one_per_project_environment');
        });
        DB::statement('ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_environment_check');
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('environment');
            $table->unique(['project_id'], 'api_keys_one_per_project');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropUnique('api_keys_one_per_project');
            $table->text('environment')->default('live');
            $table->unique(['project_id', 'environment'], 'api_keys_one_per_project_environment');
        });
        DB::statement("ALTER TABLE api_keys ADD CONSTRAINT api_keys_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }
};
