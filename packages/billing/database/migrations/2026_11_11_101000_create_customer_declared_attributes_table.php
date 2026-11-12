<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0122 §5 — les valeurs déclarées d'un client.
 *
 * Elles ne sont **pas** des colonnes de `customers` : la liste blanche de
 * colonnes d'ADR-0070 §1 reste intacte. C'est une table à part.
 *
 * Les valeurs sont conservées **en clair**, comme `display_name` : ce
 * sont des valeurs d'exploitation, ni secrètes, ni cherchées. Le seul
 * index est celui de la clé `(customer_id, key)` — l'unicité d'une
 * valeur pour un client (ADR-0122 §5).
 *
 * Aucune clé étrangère : l'effacement d'un client garde la ligne
 * (ADR-0083), et l'historique des valeurs suit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_declared_attributes', function (Blueprint $table): void {
            $table->uuid('customer_id');
            $table->uuid('project_id');
            $table->text('key');
            $table->text('value');
            $table->timestampsTz();

            $table->unique(
                ['customer_id', 'key'],
                'customer_declared_attributes_customer_key_unique',
            );
            $table->index(
                'project_id',
                'customer_declared_attributes_project_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_declared_attributes');
    }
};
