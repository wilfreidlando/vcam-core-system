<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module-owned copy of Sanctum's stock migration, not `vendor:publish`ed
 * as-is: the stock migration's `morphs('tokenable')` is `unsignedBigInteger`,
 * incompatible with this repo's uuid-everywhere convention (`operators.id`
 * is a uuid). `uuidMorphs()` matches it. Owned by `identity` since operators
 * are its only tokenable entity today (ADR-0044).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
