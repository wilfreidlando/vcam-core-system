<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0069 §1 — the platform role becomes optional: a console account may
 * carry one (it then sees every organization) or none (it sees only the
 * organizations it belongs to, through `organization_memberships`).
 *
 * Existing accounts keep their role: no access is reduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE operators ALTER COLUMN role DROP NOT NULL');
        DB::statement('ALTER TABLE operators DROP CONSTRAINT IF EXISTS operators_role_check');
        DB::statement(
            'ALTER TABLE operators ADD CONSTRAINT operators_role_check '.
            "CHECK (role IS NULL OR role IN ('platform_admin', 'finance_operator', 'support_operator', 'provider_manager', 'read_only_auditor'))"
        );
    }

    public function down(): void
    {
        // A rollback cannot invent a platform role for an account that has
        // none: refuse rather than silently widen its access.
        $withoutRole = DB::table('operators')->whereNull('role')->count();
        if ($withoutRole > 0) {
            throw new RuntimeException(
                sprintf(
                    'Rollback refused: %d console account(s) have no platform role. Give them one before rolling back (ADR-0069).',
                    $withoutRole,
                )
            );
        }

        DB::statement('ALTER TABLE operators ALTER COLUMN role SET NOT NULL');
    }
};
