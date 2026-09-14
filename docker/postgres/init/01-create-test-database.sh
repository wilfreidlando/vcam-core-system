#!/bin/bash
# The schema and invariant guardrails (rules 4, 5, 11) run against a real
# PostgreSQL instance — SQLite cannot express FOR UPDATE, deferred constraint
# triggers or exclusion constraints. Tests therefore need their own database.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE ${POSTGRES_DB}_testing OWNER $POSTGRES_USER;
EOSQL
