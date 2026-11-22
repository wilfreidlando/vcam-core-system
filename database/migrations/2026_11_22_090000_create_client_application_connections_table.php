<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0133 §2 — le branchement retenu pour un projet.
 *
 * Le Core interroge l'application sur l'adresse **déduite** du point
 * d'entrée déjà enregistré (même schéma, même hôte) ; l'opérateur peut la
 * corriger, au cas où l'API de l'application n'est pas sur le même hôte que
 * la réception des événements. Cette correction est gardée ici : elle est
 * un **réglage de branchement**, pas une donnée du commerce.
 *
 * La table vit dans la coquille applicative, comme `idempotency_keys` :
 * le module `billing` ne peut pas dépendre de `webhook` (graphe des
 * modules, G3c), c'est donc la coquille — qui traverse les deux — qui
 * tient ce réglage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_application_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            // Le point d'entrée qui porte le secret : c'est lui qui
            // authentifie la requête que le Core adresse à l'application.
            $table->uuid('endpoint_id')->nullable();
            // L'adresse corrigée par l'opérateur, quand elle l'est.
            $table->string('base_url', 512)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_application_connections');
    }
};
