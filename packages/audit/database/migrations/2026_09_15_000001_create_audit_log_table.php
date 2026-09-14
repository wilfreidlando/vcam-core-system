<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.8. Append-only: nothing in this codebase ever UPDATEs or
 * DELETEs a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            // operator:<uuid> | project:<uuid> | service:<name>
            $table->text('actor');
            $table->text('action');
            $table->text('resource');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('context')->nullable();
            $table->text('correlation_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('resource');
            $table->index('correlation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
