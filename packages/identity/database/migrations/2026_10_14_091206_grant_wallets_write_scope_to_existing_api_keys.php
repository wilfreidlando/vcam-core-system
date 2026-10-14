<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0065 — `wallets:write` joins `ApiKeyScopes::ALL`. Every key the Core
 * issued so far carries `ALL` as it stood then (`wallets:read` included);
 * those keys get the new scope too, so a Project's integration key keeps
 * meaning "the whole Core". Wildcard keys (`*`) already cover it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE api_keys
            SET scopes = array_append(scopes, 'wallets:write')
            WHERE 'wallets:read' = ANY(scopes)
              AND NOT ('wallets:write' = ANY(scopes))
              AND NOT ('*' = ANY(scopes))
            SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE api_keys SET scopes = array_remove(scopes, 'wallets:write')");
    }
};
