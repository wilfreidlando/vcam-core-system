<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0070 §1 — the customer of a project: a minimal identity, declared
 * by the project, encrypted at rest.
 *
 * The project is the source of truth for its customer; the Core keeps the
 * copy the billing and the portal need. `external_ref` is the correlation
 * key (opaque to the Core, format-checked so it can never be injected into
 * a URL, a log or a cache key).
 *
 * The email and the phone are **encrypted** (ADR-0083): only the
 * ciphertext and the blind index are stored. The blind index is what makes
 * the per-project uniqueness and the exact search possible without
 * decrypting a single row.
 *
 * The column list is a **white-list** (ADR-0070 §Conséquences): any new
 * column requires an ADR. `tests/Schema/CustomersColumnsTest.php` pins
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('external_ref');
            $table->text('display_name');
            $table->text('email_ciphertext')->nullable();
            $table->text('email_bidx')->nullable();
            $table->text('phone_ciphertext')->nullable();
            $table->text('phone_bidx')->nullable();
            $table->text('locale')->default('fr');
            $table->jsonb('metadata')->default('{}');
            $table->text('status')->default('active');
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('erased_at')->nullable();
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active', 'suspended', 'erased'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_external_ref_format_check CHECK (external_ref ~ '^[A-Za-z0-9._:-]{1,128}$')");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_metadata_object_check CHECK (jsonb_typeof(metadata) = 'object')");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_metadata_size_check CHECK (pg_column_size(metadata) <= 4096)');

        DB::statement('CREATE UNIQUE INDEX customers_project_ref_unique ON customers (project_id, external_ref)');
        DB::statement('CREATE UNIQUE INDEX customers_project_email_unique ON customers (project_id, email_bidx) WHERE email_bidx IS NOT NULL');
        DB::statement('CREATE INDEX customers_project_phone_idx ON customers (project_id, phone_bidx) WHERE phone_bidx IS NOT NULL');
        DB::statement('CREATE INDEX customers_project_created_idx ON customers (project_id, created_at, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
