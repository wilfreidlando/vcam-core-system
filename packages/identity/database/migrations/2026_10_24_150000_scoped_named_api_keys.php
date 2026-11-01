<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0082 — several named keys per project (amends ADR-0066's "one key").
 *
 * - The unique constraint on `project_id` goes; the per-project limit (10 by
 *   default) is checked at issuance, under a lock.
 * - Each key gets a name (existing keys become « Clé principale »), an
 *   optional network restriction (`allowed_cidrs`), an optional expiry, the
 *   address it was last used from, and — once rotated — the key replacing it.
 * - Existing keys keep their scopes: new scopes are never granted to them
 *   automatically (least privilege).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropUnique('api_keys_one_per_project');
            $table->text('name')->default('Clé principale');
            $table->timestampTz('expires_at')->nullable();
            $table->ipAddress('last_used_ip')->nullable();
            $table->uuid('replaced_by_api_key_id')->nullable();
            $table->foreign('replaced_by_api_key_id')->references('id')->on('api_keys')->nullOnDelete();
            $table->index(['project_id', 'created_at'], 'api_keys_project_created_index');
        });

        DB::statement("ALTER TABLE api_keys ADD COLUMN allowed_cidrs text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE api_keys ALTER COLUMN name DROP DEFAULT');
        DB::statement('ALTER TABLE api_keys ADD CONSTRAINT api_keys_name_not_blank CHECK (length(btrim(name)) BETWEEN 1 AND 80)');
    }

    public function down(): void
    {
        // Never delete a credential to make a rollback fit: refuse instead.
        $crowded = DB::table('api_keys')->select('project_id')->groupBy('project_id')->havingRaw('count(*) > 1')->exists();
        if ($crowded) {
            throw new RuntimeException('Rollback refused: at least one project has several API keys. Revoke the extra keys first (ADR-0082).');
        }

        DB::statement('ALTER TABLE api_keys DROP CONSTRAINT IF EXISTS api_keys_name_not_blank');

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropIndex('api_keys_project_created_index');
            $table->dropForeign(['replaced_by_api_key_id']);
            $table->dropColumn(['name', 'expires_at', 'last_used_ip', 'replaced_by_api_key_id', 'allowed_cidrs']);
            $table->unique(['project_id'], 'api_keys_one_per_project');
        });
    }
};
