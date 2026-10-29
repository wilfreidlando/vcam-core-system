<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit de sécurité 2026-10-29, écart #10 — `logo_url` et `support_url` du
 * portail ne pointent plus vers n'importe quel hôte : le projet déclare les
 * origines https qu'il maîtrise. Colonne nullable (les lignes existantes
 * valent « aucune origine déclarée »), aucune contrainte en base : la règle
 * (≤ 20 entrées, origine https sans chemin) vit dans `PortalSettings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->json('allowed_asset_origins')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->dropColumn('allowed_asset_origins');
        });
    }
};
