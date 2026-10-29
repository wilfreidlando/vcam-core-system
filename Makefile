# Enterprise Core System — every command runs inside the container.
# The host needs Docker and nothing else (ADR-0019).

SHELL := /bin/bash
# Not UID/GID: those are readonly in bash and the assignment fails noisily.
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC      := docker compose
EXEC    := $(DC) exec -T app
RUN     := $(DC) run --rm --no-deps app

DC_PROD := docker compose -f compose.prod.yaml

.DEFAULT_GOAL := help

# ─── Environment ────────────────────────────────────────────────────────────

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: build
build: ## Build the container images
	$(DC) build

.PHONY: up
up: ## Start the stack (app, horizon, scheduler, postgres, valkey, mailpit)
	$(DC) up -d
	@echo "App: http://localhost:$${APP_PORT:-8000} · Mail: http://localhost:$${MAILPIT_UI_PORT:-8025}"

.PHONY: down
down: ## Stop the stack
	$(DC) down

.PHONY: destroy
destroy: ## Stop the stack and delete its volumes (DESTROYS local data)
	$(DC) down -v

.PHONY: shell
shell: ## Open a shell in the app container
	$(DC) exec app bash

.PHONY: logs
logs: ## Tail container logs
	$(DC) logs -f --tail=100

.PHONY: install
install: ## Install dependencies and prepare the local environment
	@test -f .env || cp .env.example .env
	$(EXEC) composer install
	$(EXEC) php artisan key:generate
	$(MAKE) migrate

.PHONY: migrate
migrate: ## Run database migrations
	$(EXEC) php artisan migrate

.PHONY: fresh
fresh: ## Drop and rebuild the database from scratch
	$(EXEC) php artisan migrate:fresh

# ─── Quality gates (mirror the CI jobs one for one) ─────────────────────────

.PHONY: lint
lint: ## Fix code style
	$(EXEC) vendor/bin/pint

.PHONY: lint-check
lint-check: ## Check code style without fixing (CI job: hygiene)
	$(EXEC) vendor/bin/pint --test

.PHONY: stan
stan: ## Static analysis: PHPStan + Larastan + PHPat + custom rules (CI job: static)
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: arch
arch: ## Architecture boundaries: Deptrac modules + layers, arch tests (CI job: arch)
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac-modules.yaml
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac-layers.yaml
	$(EXEC) vendor/bin/pest --group=arch

.PHONY: arch-graph
arch-graph: ## Render the module dependency graph
	$(EXEC) vendor/bin/deptrac analyse --config-file=deptrac-modules.yaml \
		--formatter=graphviz-image --output=docs/architecture/module-graph.png

.PHONY: schema
schema: ## Schema guardrails: anti-PII, money columns (CI job: schema)
	$(EXEC) php artisan migrate:fresh --env=testing
	$(EXEC) vendor/bin/pest --group=schema

.PHONY: test
test: ## Run the test suite in parallel (CI job: tests)
	$(EXEC) vendor/bin/pest --parallel

# ─── Sectors (parallel work) ────────────────────────────────────────────────
# One sector = one bounded context = one agent = one test database. A sector
# agent runs its OWN suite and the gates its own change can break — never the
# whole ninety-nine seconds. The integration gate (`make ci`) runs everything,
# once, at the end.
#
# Each session MUST pass its own database: the suite is shared otherwise.
DB      ?= core_testing
SECTOR  ?=

.PHONY: sectors
sectors: ## List the test sectors (one bounded context each)
	@printf '  %-22s %s\n' \
	  'billing' 'clients, catalogue, abonnements, factures, encaissement' \
	  'payment' 'paiements, prestataires, callbacks, refunds' \
	  'identity' 'organisations, projets, cles, operateurs, MFA' \
	  'ledger' 'grand livre, invariants, exceptions d argent' \
	  'wallet' 'portefeuilles, transferts, sorties' \
	  'parties' 'tiers sans identite' \
	  'notification' 'notifications, cascade de secours, WAHA' \
	  'webhook' 'endpoints, livraisons, DLQ, rejeu' \
	  'events' 'outbox transactionnelle, relais' \
	  'audit' 'journal infalsifiable, lecture scopee' \
	  'admin' 'console operateur' \
	  'support' 'socle partage' \
	  'fees' 'moteur de frais (scaffold)' \
	  'storage' 'fichiers (scaffold)' \
	  'developer-platform' 'registre de capacites (scaffold)'

.PHONY: test-sector
test-sector: ## Run one sector's suite: make test-sector SECTOR=billing DB=core_testing_s1
	@test -n "$(SECTOR)" || { echo "SECTOR is required — see 'make sectors'"; exit 1; }
	$(DC) exec -T -e DB_DATABASE=$(DB) app vendor/bin/pest --testsuite=$(SECTOR) --parallel --do-not-fail-on-empty-test-suite

.PHONY: check-sector
check-sector: ## The gate a sector agent runs: style, static analysis, its suite, the schema, its IDOR cases
	@test -n "$(SECTOR)" || { echo "SECTOR is required — see 'make sectors'"; exit 1; }
	$(EXEC) vendor/bin/pint --test
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(DC) exec -T -e DB_DATABASE=$(DB) app vendor/bin/pest --testsuite=$(SECTOR) --parallel --do-not-fail-on-empty-test-suite
	$(DC) exec -T -e DB_DATABASE=$(DB) app vendor/bin/pest --testsuite=Schema --parallel
	case "$(SECTOR)" in \
	  billing|identity|wallet|notification|payment|ledger|webhook) \
	    $(DC) exec -T -e DB_DATABASE=$(DB) app vendor/bin/pest \
	      tests/Architecture/Idor/ConsoleIdorTest.php --filter='$(SECTOR)::' --parallel ;; \
	esac

.PHONY: coverage
coverage: ## Run the test suite with coverage
	$(EXEC) vendor/bin/pest --coverage --min=80

.PHONY: hygiene
hygiene: ## Composer and secret hygiene (CI job: hygiene)
	# Not --strict: it flags every internal core/* path-repo package's "*@dev"
	# constraint as an error, which is Composer's advice for packages meant to
	# be published on Packagist — these never will be (ADR-0002).
	$(EXEC) composer validate
	$(EXEC) composer audit --locked

.PHONY: ci
ci: hygiene lint-check stan arch schema test ## Run every gate, exactly as CI does

.PHONY: guard-test
guard-test: ## Prove the guardrails bite: inject violations, expect failures, revert
	./tools/guards/verify-guards.sh

# ─── Convenience ────────────────────────────────────────────────────────────

.PHONY: openapi
openapi: ## Regenerate both OpenAPI snapshots (integration + admin console, ADR-0049)
	$(EXEC) php artisan scramble:export --path=openapi/v1.json
	$(EXEC) php artisan scramble:export --api=admin --path=openapi/admin.json

.PHONY: horizon-status
horizon-status: ## Show queue worker status
	$(EXEC) php artisan horizon:status

# ─── Production (ADR-0053) ──────────────────────────────────────────────────
# Run on the VPS, next to a filled-in .env (see .env.production.example) and
# a pre-existing external `nginx-proxy` network.

# ─── VPS standard: staging / production (ADR-0064) ─────────────────────────
# Platform tools live in their own repository (vcam-infra-deploy), cloned
# on the server in /app/vps-platform. Staging checkout: make staging-deploy ·
# production checkout: make prod-promote.
PLATFORM_DIR ?= /app/vps-platform
DEPLOY := $(PLATFORM_DIR)/bin/deploy.sh

# Fails FIRST, in plain words, when the platform is not where PLATFORM_DIR says (instead of "No such file or directory" half-way through a deploy).
.PHONY: _platform
_platform:
	@test -x "$(DEPLOY)" || { echo "make : plateforme introuvable ($(DEPLOY))." >&2; echo "  Dossier attendu : $(PLATFORM_DIR). S'il a changé : make <cible> PLATFORM_DIR=<dossier>. Sur le serveur elle se clone dans /app/vps-platform (guide 3 de vcam-infra-deploy)." >&2; exit 127; }

.PHONY: staging-deploy
staging-deploy: _platform ## Build REF (default origin/main) and deploy it to staging
	sha=$$($(DEPLOY) build $(REF)) && $(DEPLOY) up staging $$sha

.PHONY: prod-promote
prod-promote: _platform ## Deploy to production the exact version running on staging (asks for confirmation)
	$(DEPLOY) promote $(SHA)

.PHONY: rollback
rollback: _platform ## Redeploy the previous version: make rollback ENV=prod|staging
	$(DEPLOY) rollback $(ENV)

.PHONY: deploy-status
deploy-status: _platform ## Versions and containers of staging and production
	$(DEPLOY) status

.PHONY: prod-backup
prod-backup: ## Encrypted database backup right now (local + S3)
	$(DC_PROD) -p core-system-prod --env-file .env run --rm backup backup.sh

.PHONY: vps-audit
vps-audit: _platform ## Read-only compliance report of every container on the VPS
	$(PLATFORM_DIR)/bin/vps-audit.sh

.PHONY: vps-inventory
vps-inventory: _platform ## Read-only, secret-free Markdown inventory of the whole VPS (platform guide 13)
	$(PLATFORM_DIR)/bin/vps-inventory.sh

.PHONY: vps-check
vps-check: _platform ## Pre-flight of a deploy, changes nothing (ENV=staging|prod) — run from the env's checkout
	$(PLATFORM_DIR)/bin/deploy.sh check $(or $(ENV),staging)

.PHONY: prod-build
prod-build: ## Build the production images (app/horizon/scheduler share one FrankenPHP image)
	$(DC_PROD) build

.PHONY: prod-up
prod-up: ## Start the production stack (does not run migrations — see prod-migrate)
	$(DC_PROD) up -d

.PHONY: prod-down
prod-down: ## Stop the production stack (does not delete volumes)
	$(DC_PROD) down

.PHONY: prod-migrate
prod-migrate: ## Run database migrations against the production stack
	$(DC_PROD) exec app php artisan migrate --force

.PHONY: prod-logs
prod-logs: ## Tail production container logs
	$(DC_PROD) logs -f --tail=100

.PHONY: prod-shell
prod-shell: ## Open a shell in the production app container
	$(DC_PROD) exec app bash

.PHONY: e2e-seed
e2e-seed: ## Seed a project, a console login and a portal link for the browser end-to-end session (dev only)
	@$(EXEC) php artisan tinker --execute="require 'tools/checks/e2e-seed.php';" | grep -o '^\(LINK\|E2E_[A-Z_]*\)=.*' | sed "s#localhost:8000#localhost:$${APP_PORT:-8010}#"

.PHONY: portal-demo
portal-demo: ## Seed demo data and print a fresh one-time link to the customer portal (dev only)
	@$(EXEC) php artisan tinker --execute="require 'tools/checks/portal-demo.php';" | grep -o 'LINK=.*' | sed "s/^LINK=//; s#localhost:8000#localhost:$${APP_PORT:-8010}#"
