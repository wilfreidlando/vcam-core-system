<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0094 §4 — the settings of the invoices, added to the ones the
 * subscriptions already had (ADR-0092 §4).
 *
 * Five settings, five places they matter:
 *
 * - `invoice_prefix` — the prefix of the invoice number (ADR-0094 §5);
 * - `default_tax_rate_bps` — the rate a manual line takes when the
 *   caller names none (ADR-0073 §1: a rate, never a tax regime);
 * - `manual_receipt_approval_threshold_minor` — above it, an offline
 *   receipt needs a second pair of eyes (ADR-0073 §4);
 * - `retain_invoice_identity` — whether an erasure keeps the identity
 *   frozen on the draft invoices (ADR-0094 §7);
 * - `document_branding` — the free marks of the printed documents
 *   (ADR-0073 §5).
 *
 * The columns are added with their default: the projects that already
 * have a settings row keep it, and the ones that have none get the
 * defaults of the domain model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->text('invoice_prefix')->default('FACT');
            $table->integer('default_tax_rate_bps')->default(0);
            $table->bigInteger('manual_receipt_approval_threshold_minor')->default(0);
            $table->boolean('retain_invoice_identity')->default(true);
            $table->jsonb('document_branding')->default('{}');
        });

        DB::statement("ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_invoice_prefix_check CHECK (invoice_prefix ~ '^[A-Z0-9]{2,12}$')");
        DB::statement('ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_default_tax_rate_bps_check CHECK (default_tax_rate_bps BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_manual_receipt_threshold_check CHECK (manual_receipt_approval_threshold_minor >= 0)');
        DB::statement("ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_document_branding_check CHECK (jsonb_typeof(document_branding) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE billing_settings DROP CONSTRAINT IF EXISTS billing_settings_document_branding_check');
        DB::statement('ALTER TABLE billing_settings DROP CONSTRAINT IF EXISTS billing_settings_manual_receipt_threshold_check');
        DB::statement('ALTER TABLE billing_settings DROP CONSTRAINT IF EXISTS billing_settings_default_tax_rate_bps_check');
        DB::statement('ALTER TABLE billing_settings DROP CONSTRAINT IF EXISTS billing_settings_invoice_prefix_check');

        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_prefix',
                'default_tax_rate_bps',
                'manual_receipt_approval_threshold_minor',
                'retain_invoice_identity',
                'document_branding',
            ]);
        });
    }
};
