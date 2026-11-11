<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0122 §1, §2 — la déclaration de la forme du locataire d'un projet.
 *
 * Une déclaration est une **forme**, jamais des valeurs : la colonne
 * `fields` porte une liste de noms (`{key, label, type, required,
 * max_length, source}`), et `unique_key_pattern` porte le motif que doit
 * respecter la référence du Core.
 *
 * Les règles vivent dans le code (le domaine) ; la base ne porte que les
 * clés uniques :
 *
 * - `(project_id, version)` — une version est une version ;
 * - `(project_id) WHERE status = 'accepted'` — **une seule** déclaration
 *   en force par projet. L'index est partiel : le Schema builder de
 *   Laravel n'exprime pas les index conditionnels, d'où la première
 *   ligne de `DB::statement()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tenant_schemas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->integer('version');
            $table->text('status');
            $table->text('unique_key');
            $table->text('unique_key_pattern');
            $table->jsonb('fields');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['project_id', 'version'],
                'project_tenant_schemas_project_version_unique',
            );
            $table->index(
                ['project_id', 'status'],
                'project_tenant_schemas_project_status_idx',
            );
        });

        DB::statement(
            'CREATE UNIQUE INDEX project_tenant_schemas_project_accepted_unique '.
            'ON project_tenant_schemas (project_id) WHERE status = \'accepted\'',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tenant_schemas');
    }
};
