<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0119 §1 — le catalogue des packs du projet.
 *
 * Un pack est une **déclaration du projet** : il vit dans ses réglages,
 * au même titre que les correspondances capacité → compteur (ADR-0074 §5).
 *
 * La colonne porte une **liste** JSON d'offres :
 * `{"code", "meter", "quantity", "unit_price_minor", "label"}` — le
 * compteur étendu, la quantité, le prix d'une unité en unités mineures
 * (G5) et le libellé lu par le client.
 *
 * Aucune contrainte en base : les règles (compteur connu du projet,
 * quantité positive, prix positif, libellé non vide, code unique) sont
 * vérifiées par le code (ADR-0098 §6, ADR-0074 §1). La migration passe
 * par le Schema builder, sans SQL brut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->jsonb('supplement_packs')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->dropColumn('supplement_packs');
        });
    }
};
