#!/usr/bin/env python3
"""PostToolUse hook: fast local feedback right after Claude edits a PHP file.

Runs Pint (style) and a PHPStan pass scoped to the touched file, inside the
project's dev container. Mirrors what lefthook does at commit time and what CI
does on every PR, but fires immediately after Edit/Write so a violation of one
of this project's guardrails (see docs/architecture/garde-fous-inactifs.md) is
visible before the change is even reviewed, not after a CI round-trip.

Deliberately narrow: only Pint + PHPStan on the single file. Deptrac and the
full test suite need the whole codebase and are too slow to run on every edit;
they stay in `make arch` / `make ci` / pre-push.

Reads the PostToolUse JSON payload on stdin and exits non-zero (surfacing
stderr to Claude) only on a real violation — never on missing tooling, so a
half-provisioned environment doesn't block editing.
"""

import json
import subprocess
import sys
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[2]


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError:
        return 0

    file_path = payload.get("tool_input", {}).get("file_path")
    if not file_path or not file_path.endswith(".php"):
        return 0

    absolute = Path(file_path)
    if not absolute.is_absolute():
        absolute = PROJECT_ROOT / file_path

    try:
        relative = absolute.relative_to(PROJECT_ROOT)
    except ValueError:
        return 0  # Outside the project; nothing to check.

    if not absolute.exists():
        return 0  # Deleted or moved since the tool call.

    if "/vendor/" in str(relative) or str(relative).startswith("vendor/"):
        return 0

    # Docker not running yet (e.g. before `make up`) — don't block the edit.
    probe = subprocess.run(
        ["docker", "compose", "ps", "--status=running", "--services"],
        cwd=PROJECT_ROOT, capture_output=True, text=True, timeout=10,
    )
    if "app" not in probe.stdout:
        return 0

    def run_in_container(*args: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            ["docker", "compose", "exec", "-T", "app", *args],
            cwd=PROJECT_ROOT, capture_output=True, text=True, timeout=60,
        )

    problems = []

    pint = run_in_container("vendor/bin/pint", "--test", str(relative))
    if pint.returncode != 0:
        problems.append(f"Pint (code style):\n{pint.stdout}{pint.stderr}")

    stan = run_in_container(
        "vendor/bin/phpstan", "analyse", "--no-progress",
        "--memory-limit=512M", str(relative),
    )
    if stan.returncode != 0:
        problems.append(f"PHPStan (level 10 + architecture rules):\n{stan.stdout}{stan.stderr}")

    if problems:
        print(f"Quality gate failed for {relative}:\n\n" + "\n\n".join(problems), file=sys.stderr)
        return 2  # Non-blocking exit code convention: shown to Claude, not fatal to the user.

    return 0


if __name__ == "__main__":
    sys.exit(main())
