<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0072 §9 — « this step was announced once ». The scheduler passes every
 * five minutes: an event that must leave once per contract (the trial that
 * ends in three days) needs a mark that survives the passes.
 *
 * The primary key is the whole mechanism — inserting twice is how the second
 * pass learns it is late. No other rule lives in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_notices', function (Blueprint $table): void {
            $table->uuid('subscription_id');
            $table->string('notice', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['subscription_id', 'notice']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notices');
    }
};
