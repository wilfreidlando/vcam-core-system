<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects');
            $table->text('environment');
            // Visible identifier used for lookup, e.g. pk_live_ab12cd34.
            $table->text('prefix')->unique();
            // Hash of the full key. The key itself is shown once at issuance and never stored.
            $table->text('key_hash');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE api_keys ADD COLUMN scopes text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE api_keys ADD CONSTRAINT api_keys_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
