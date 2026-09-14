#!/usr/bin/env bash
#
# Prove the guardrails actually bite.
#
# A rule nobody has ever seen fail is not a guardrail, it is an intention. This
# script introduces a real violation of each ACTIVE guardrail, requires the gate
# to reject it WITH THAT GUARDRAIL'S OWN MESSAGE, and then reverts the change.
#
# The message check matters: a probe that fails PHPStan for an unrelated reason
# (a typo, an unused property) would otherwise pass as "caught" and hide a
# guardrail that protects nothing.
#
# Run it after changing any guardrail, and before trusting a green build:
#     make guard-test
#
# Guards that cannot fail yet are listed in
# docs/architecture/garde-fous-inactifs.md and get a probe here the day the code
# they protect exists.

set -uo pipefail

cd "$(dirname "$0")/../.."

DC="docker compose run --rm --no-deps -T app"
DC_DB="docker compose run --rm -T app"
STAN="vendor/bin/phpstan analyse --memory-limit=1G --no-progress --error-format=json"

PASSED=0
FAILED=0
CLEANUP=()

reset_workspace() {
    for path in "${CLEANUP[@]}"; do
        if [ -e "$path.guardbak" ]; then
            mv "$path.guardbak" "$path"
        else
            rm -f "$path"
        fi
    done
    CLEANUP=()
    # Probes may create directories. Every scaffold directory carries a .gitkeep,
    # so only probe leftovers can be empty.
    find packages -type d -empty -delete
}

trap reset_workspace EXIT

backup() {
    cp "$1" "$1.guardbak"
    CLEANUP+=("$1")
}

# probe <path>  — writes stdin to <path> and schedules its removal
probe() {
    mkdir -p "$(dirname "$1")"
    cat > "$1"
    CLEANUP+=("$1")
}

# expect_rejection <id> <description> <expected output regex> <command...>
expect_rejection() {
    local id="$1" description="$2" pattern="$3"
    shift 3

    printf '\n\033[1m%s\033[0m — %s\n' "$id" "$description"

    local output
    output="$(mktemp)"

    if "$@" > "$output" 2>&1; then
        printf '  \033[31mNOT CAUGHT\033[0m — the violation passed the gate.\n'
        tail -15 "$output" | sed 's/^/    /'
        FAILED=$((FAILED + 1))
    elif grep -Eq "$pattern" "$output"; then
        printf '  \033[32mcaught\033[0m — rejected with the guardrail'"'"'s own message.\n'
        PASSED=$((PASSED + 1))
    else
        printf '  \033[31mWRONG REASON\033[0m — the gate failed, but not because of this guardrail.\n'
        printf '  Expected output matching: %s\n' "$pattern"
        tail -15 "$output" | sed 's/^/    /'
        FAILED=$((FAILED + 1))
    fi

    rm -f "$output"
    reset_workspace
}

printf '\033[1mVerifying that each active guardrail rejects a real violation.\033[0m\n'

# ── G1b / G2 — the module dependency graph ──────────────────────────────────
backup packages/ledger/composer.json
python3 - <<'PY'
import json
path = 'packages/ledger/composer.json'
manifest = json.load(open(path))
manifest['require']['core/payment'] = '*@dev'
json.dump(manifest, open(path, 'w'), indent=4)
PY
expect_rejection "G1b/G2" "the Ledger declares a dependency on core/payment" \
    'module graph forbids|nothing beyond PHP' \
    $DC vendor/bin/pest --group=arch

# ── G3 — a layer pointing outward ───────────────────────────────────────────
probe packages/ledger/src/Infrastructure/Persistence/Eloquent/GuardProbeRecord.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

final class GuardProbeRecord {}
PHP
probe packages/ledger/src/Application/UseCase/GuardProbeLayer.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Infrastructure\Persistence\Eloquent\GuardProbeRecord;

final class GuardProbeLayer
{
    public function record(): GuardProbeRecord
    {
        return new GuardProbeRecord();
    }
}
PHP
expect_rejection "G3" "a use case depends on Infrastructure instead of a Domain port" \
    'must not depend on' \
    $DC vendor/bin/deptrac analyse --config-file=deptrac-layers.yaml --no-progress

# ── G3b — a Domain class coupled to the framework ───────────────────────────
probe packages/ledger/src/Domain/Model/GuardProbe.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

use Illuminate\Database\Eloquent\Model;

final class GuardProbe extends Model {}
PHP
expect_rejection "G3b" "a Domain entity extends an Eloquent model" \
    'should not depend on Illuminate' \
    $DC $STAN packages/ledger

# ── G3c — another module's internals used directly ──────────────────────────
probe packages/ledger/src/Domain/Model/GuardProbeEntry.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

final class GuardProbeEntry {}
PHP
probe packages/wallet/src/Application/UseCase/GuardProbeCrossModule.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Ledger\Domain\Model\GuardProbeEntry;

final class GuardProbeCrossModule
{
    public function entry(): GuardProbeEntry
    {
        return new GuardProbeEntry();
    }
}
PHP
expect_rejection "G3c" "Wallet uses a Ledger Domain class instead of Ledger's Contracts" \
    'should not depend on [^"]*GuardProbeEntry' \
    $DC $STAN packages/wallet packages/ledger

# ── G3d — persistence inside the shared kernel ──────────────────────────────
probe packages/support/src/GuardProbeStore.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Support;

use Illuminate\Support\Facades\DB;

final class GuardProbeStore
{
    public function count(): int
    {
        return DB::table('guard_probe')->count();
    }
}
PHP
expect_rejection "G3d" "the shared kernel queries the database" \
    'should not depend on Illuminate[^"]*DB' \
    $DC $STAN packages/support

# ── G5 — money stored as a decimal column ───────────────────────────────────
probe packages/ledger/database/migrations/2026_09_14_999999_guard_probe.php <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guard_probe_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->decimal('balance_minor', 19, 4);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guard_probe_accounts');
    }
};
PHP
expect_rejection "G5" "a balance column is declared decimal instead of bigint" \
    'integer minor units|must be bigint' \
    $DC_DB vendor/bin/pest --group=schema

# ── G6 — a raw provider status outside its adapter ──────────────────────────
probe packages/payment/src/Application/UseCase/GuardProbeStatus.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

final class GuardProbeStatus
{
    public function isDone(string $status): bool
    {
        return $status === 'SUCCESSFUL';
    }
}
PHP
expect_rejection "G6" "MTN's raw 'SUCCESSFUL' status leaks into a use case" \
    'Raw provider status' \
    $DC $STAN packages/payment

# ── G6c — an adapter opening its own HTTP connection ────────────────────────
probe packages/payment/src/Infrastructure/Provider/Mtn/GuardProbeClient.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\Mtn;

use Illuminate\Support\Facades\Http;

final class GuardProbeClient
{
    public function reachable(): bool
    {
        return Http::get('https://provider.invalid')->successful();
    }
}
PHP
expect_rejection "G6c" "a provider adapter calls Http:: directly" \
    'should not depend on Illuminate[^"]*Http' \
    $DC $STAN packages/payment

# ── G8 — an event dispatched outside the outbox ─────────────────────────────
probe packages/wallet/src/Application/UseCase/GuardProbeEvent.php <<'PHP'
<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

final class GuardProbeEvent
{
    public function credit(): void
    {
        event('wallet.credited');
    }
}
PHP
expect_rejection "G8" "a use case calls event() instead of writing to the outbox" \
    'outside the transactional outbox' \
    $DC $STAN packages/wallet

# ── Summary ─────────────────────────────────────────────────────────────────
printf '\n\033[1m%d guardrail(s) rejected their violation, %d did not.\033[0m\n' "$PASSED" "$FAILED"

if [ "$FAILED" -gt 0 ]; then
    printf '\033[31mA guardrail that does not reject its violation gives false assurance.\033[0m\n'
    exit 1
fi

printf '\033[32mEvery active guardrail rejected its violation, for the right reason.\033[0m\n'
