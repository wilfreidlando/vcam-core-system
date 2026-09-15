<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.2. An explicit allow-list, not left to brick/money's
 * built-in ISO knowledge: every currency column in the Core references this
 * table, so an unsupported code can never be inserted anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->smallInteger('exponent');
        });

        // MVP scope (plan de delivery §1.1): XAF/Cameroon. USD seeded for the
        // Money wrapper's own tests and any future multi-currency need.
        DB::table('currencies')->insert([
            ['code' => 'XAF', 'exponent' => 0],
            ['code' => 'USD', 'exponent' => 2],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
