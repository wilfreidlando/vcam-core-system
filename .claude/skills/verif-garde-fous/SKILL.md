---
name: verif-garde-fous
description: Run the full guardrail suite (CI mirror) and the guard-test violation-injection script, then interpret and report results. Use before merging any change, or when asked "are the guardrails still working".
allowed-tools: Read, Bash(make *), Bash(docker compose *)
---

# Vérification des garde-fous

Deux niveaux de vérification, à ne pas confondre :

1. **`make ci`** — les gates passent-ils sur le code actuel ?
2. **`make guard-test`** — les gates détecteraient-ils une vraie violation
   s'il y en avait une ? C'est la question la plus importante : un garde-fou
   qu'on n'a jamais vu échouer n'est pas un garde-fou, c'est une intention
   (`tools/guards/verify-guards.sh`).

## Procédure

1. `make ci` — exécute dans l'ordre : `hygiene` (composer validate/audit,
   style), `stan` (PHPStan niveau 10 + PHPat + règles maison), `arch` (Deptrac
   modules + couches + tests d'architecture), `schema` (garde-fous PostgreSQL),
   `test` (suite complète).

2. En cas d'échec, identifier **quel garde-fou** a parlé — le message
   d'erreur nomme toujours la règle (ex. « The Ledger is the financial core... »
   pour G2, « Raw provider status... » pour G6). Ne jamais contourner en
   supprimant le test ou en assouplissant la règle sans passer par
   `architecte-core`.

3. `make guard-test` — injecte une violation réelle de chacun des garde-fous
   **actifs** (`docs/architecture/garde-fous-inactifs.md`, section « actifs »),
   vérifie que la CI la rejette **avec le message de ce garde-fou précis**
   (pas juste un échec générique), puis annule la modification. Le script
   nettoie toujours après lui, même en cas d'interruption (`trap` sur `EXIT`).

4. Si `make guard-test` signale « NOT CAUGHT » ou « WRONG REASON » pour un
   garde-fou : c'est un incident à traiter avant tout autre travail — un
   garde-fou qui ne mord plus donne une fausse assurance à toute l'équipe.

5. Vérifier la cohérence du registre :
   `tests/Architecture/GuardCoverageTest.php` doit passer — il échoue si un
   garde-fou `pending-code` n'est pas listé dans
   `docs/architecture/garde-fous-inactifs.md`, ou si un garde-fou `active` y
   est encore listé par erreur.

## Ajouter un garde-fou à `verify-guards.sh`

Chaque garde-fou actif a une sonde dédiée dans le script : elle introduit une
violation minimale, appelle `expect_rejection` avec l'identifiant, une
description, un **motif regex qui matche le message spécifique du
garde-fou** (pas un message générique), et la commande qui doit le détecter.
Voir les sondes G3c/G3d/G6c pour un modèle récent.

## Ne jamais faire

- Considérer `make ci` vert comme suffisant sans avoir fait tourner
  `make guard-test` au moins une fois après avoir touché un garde-fou.
- Assouplir un pattern regex de garde-fou pour faire passer un cas particulier
  sans vérifier qu'il ne devient pas trop large (faux négatifs).
