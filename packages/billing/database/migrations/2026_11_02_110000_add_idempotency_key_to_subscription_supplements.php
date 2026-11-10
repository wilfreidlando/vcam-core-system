<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0119 §6 — la clé du geste qui a produit un supplément.
 *
 * Le portail achète un pack : ce geste émet une facture et inscrit une
 * créance au grand livre (ADR-0117 §4), il est donc idempotent (G7).
 * Comme le portail appelle les contrats de `billing` dans le processus —
 * il n'a pas de client HTTP — la clé est **durable** : elle est portée
 * par le supplément.
 *
 * Un index unique sur `(project_id, idempotency_key)` rend la clé unique
 * par projet : c'est la primitive de concurrence de l'idempotence, pas
 * une règle métier (ADR-0098 §6). Les NULL sont distincts en PostgreSQL :
 * les suppléments achetés par la route opérateur (qui n'ont pas de clé)
 * se suivent sans se gêner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_supplements', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->unique(['project_id', 'idempotency_key'], 'subscription_supplements_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_supplements', function (Blueprint $table): void {
            $table->dropUnique('subscription_supplements_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
