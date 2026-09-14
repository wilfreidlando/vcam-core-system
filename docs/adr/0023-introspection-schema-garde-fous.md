# ADR-0023 — Garde-fous de schéma par introspection plutôt que par parsing de migrations

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : D3, D6

## Contexte

Deux garde-fous doivent inspecter la structure des tables : G4 (aucune colonne
PII sur `parties`) et G5 (aucune colonne monétaire non-`bigint`). Une migration
est du code exécutable : `create_api_keys_table` (Lot 1) ajoute une colonne et
une contrainte via `DB::statement` brut. Un parseur statique de migrations ne
verrait ni ce `DB::statement`, ni un futur `->change()`, ni une macro.

## Décision

Les garde-fous G4 et G5 inspectent le **schéma migré** (`migrate:fresh` sur
PostgreSQL, puis lecture d'`information_schema.columns`), jamais le code des
fichiers de migration. Un `grep` sur `->decimal(|->float(|->double(` reste en
pre-commit (`lefthook.yml`) comme pré-filtre rapide et **non autoritaire**.

## Alternatives écartées

- **Parsing AST des migrations** : plus rapide (pas de base à démarrer), mais
  démontrablement aveugle au SQL brut — la migration `api_keys` en est la preuve
  vivante dans ce dépôt même.

## Conséquences

- Les suites `schema` exigent PostgreSQL (Docker ou services CI) ; elles ne
  peuvent pas tourner sur un poste sans conteneur.
