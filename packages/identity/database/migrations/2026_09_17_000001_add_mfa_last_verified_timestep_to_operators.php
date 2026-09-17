<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC 6238 §5.2 anti-replay: without recording which TOTP time step an
 * operator last verified, a code observed once (log leak,
 * shoulder-surfing) stays valid for reuse across its whole ~90s window
 * (review finding, Lot 6 follow-up). `Google2faTotpProvider::verify()`
 * takes this as `$afterTimestep` and rejects anything not strictly
 * newer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->bigInteger('mfa_last_verified_timestep')->nullable()->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('mfa_last_verified_timestep');
        });
    }
};
