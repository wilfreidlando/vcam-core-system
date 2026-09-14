---
name: nouveau-contexte-borne
description: Scaffold a new bounded-context module (packages/<name>) with the hexagonal layout, registers it in the module graph, Deptrac, and the G3c PHPat rule. Use when the architecte-core agent (or the user) has approved a new module through the four gates.
allowed-tools: Read, Write, Edit, Bash(docker compose *)
---

# Nouveau contexte borné

Un module ne se crée qu'après que les quatre portes d'entrée aient été
validées (agent `architecte-core`) et qu'un ADR l'enregistre. Cette skill
suppose que c'est déjà fait — elle scaffolde, elle ne décide pas.

## Étapes

1. **Confirmer les prérequis** : demander le nom du module (kebab-case), sa
   responsabilité en une phrase, le lot de livraison, et la liste des modules
   dont il a le droit de dépendre (toujours au minimum `support`).

2. **Ajouter l'entrée dans `tests/Architecture/ModuleGraph.php`** :
   - `ALLOWED['<module>'] = ['support', ...autres modules approuvés]`
   - `LAYER_NAMES['<module>'] = '<NomPascalCase>'`

3. **Ajouter la couche dans `deptrac-modules.yaml`** : un bloc `layers` avec un
   `directory` collector sur `packages/<module>/.*`, et l'entrée correspondante
   dans `ruleset` avec exactement les mêmes dépendances que dans `ModuleGraph`.

4. **Créer l'arborescence** :
   ```
   packages/<module>/
     composer.json          # name: core/<module>, require: php ^8.4, core/support
     README.md              # responsabilité, lot, tableau des dépendances autorisées
     src/
       <Module>ServiceProvider.php
       Domain/{Model,Event,Exception,Port}/
       Application/{UseCase,Dto}/
       Infrastructure/Persistence/Eloquent/
       Presentation/Http/{Controllers,Requests,Resources}/
       Contracts/
     database/migrations/
     routes/
     tests/{Unit,Integration}/
   ```
   Chaque répertoire vide reçoit un `.gitkeep`. Le `<Module>ServiceProvider`
   suit le modèle de `packages/ledger/src/LedgerServiceProvider.php` : registre
   ses migrations dans `boot()`, ses bindings de ports dans `register()`.

5. **Ajouter la règle G3c dans
   `tools/PhpStan/Architecture/ModulesMeetOnlyThroughContracts.php`** : une
   nouvelle méthode `test_<module>_uses_other_modules_through_contracts_only()`
   qui appelle `$this->contractsOnly('<NomPascalCase>')`. Sans elle,
   `ComposerGraphTest::every_module_is_covered_by_the_contracts_only_rule`
   échoue.

6. **Ajouter le module au root `composer.json`** :
   `"core/<module>": "*@dev"` dans `require`, ordre alphabétique.

7. **Vérifier** :
   ```
   docker compose run --rm --no-deps app composer update core/<module> --no-interaction
   make arch
   ```
   `every_package_directory_is_declared_in_the_module_graph`,
   `every_module_is_a_deptrac_layer`, `deptrac_ruleset_matches_the_module_graph`
   et `every_module_is_covered_by_the_contracts_only_rule` doivent tous passer.

8. **Documenter** : mettre à jour `docs/adr/README.md` (tableau des ADR) si le
   module fait l'objet d'un ADR dédié, et `docs/specs/spec-technique.md` §4
   n'a normalement pas besoin de changer (les 15 modules y sont déjà listés) —
   sauf si ce module n'y figurait pas, auquel cas c'est un signal à vérifier
   avec `architecte-core` avant de continuer.

## Ne jamais faire

- Créer un module sans passer par `architecte-core` d'abord.
- Donner au nouveau module une dépendance vers `ledger` sans que ce soit
  strictement nécessaire — `ledger` doit rester la dépendance la plus rare du
  graphe.
- Oublier l'étape 5 : c'est le trou exact que la revue du 2026-09-14 a trouvé
  (voir [ADR-0027](../../../docs/adr/0027-read-models-et-acces-inter-modules.md)).
