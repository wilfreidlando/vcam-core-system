<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0071 §2 — a published version is immutable.
 *
 * The terms of a plan version are frozen the moment it is published: the
 * price, the currency, the interval, the trial, the grace and the
 * features. Changing a price is **publishing a new version** — the
 * subscriptions already signed keep the terms they were signed on.
 *
 * The guard is a **trigger**, not an application check: a row written by
 * any path (a console, a script, a `psql` session) is protected the same
 * way. The only transition a published version may undergo is
 * `published → retired`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION plan_version_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('published', 'retired') THEN
                    IF NEW.price_minor IS DISTINCT FROM OLD.price_minor
                       OR NEW.currency IS DISTINCT FROM OLD.currency
                       OR NEW.interval_unit IS DISTINCT FROM OLD.interval_unit
                       OR NEW.interval_count IS DISTINCT FROM OLD.interval_count
                       OR NEW.trial_days IS DISTINCT FROM OLD.trial_days
                       OR NEW.grace_days IS DISTINCT FROM OLD.grace_days
                       OR NEW.grace_access IS DISTINCT FROM OLD.grace_access
                       OR NEW.features IS DISTINCT FROM OLD.features
                       OR NEW.plan_id IS DISTINCT FROM OLD.plan_id
                       OR NEW.project_id IS DISTINCT FROM OLD.project_id
                       OR NEW.version IS DISTINCT FROM OLD.version THEN
                        RAISE EXCEPTION 'A published plan version is immutable (ADR-0071 §2) — publish a new version instead.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.status <> 'retired' AND OLD.status = 'published' THEN
                        RAISE EXCEPTION 'A published plan version can only be retired (ADR-0071 §2).'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.status <> 'retired' AND OLD.status = 'retired' THEN
                        RAISE EXCEPTION 'A retired plan version stays retired (ADR-0071 §2).'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER plan_version_immutable BEFORE UPDATE ON plan_versions FOR EACH ROW EXECUTE FUNCTION plan_version_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS plan_version_immutable ON plan_versions');
        DB::statement('DROP FUNCTION IF EXISTS plan_version_immutable()');
    }
};
