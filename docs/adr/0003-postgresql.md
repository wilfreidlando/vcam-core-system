# ADR-0003 — PostgreSQL, y compris pour les tests

- **Statut** : Accepté (spécification technique v1.0, ADR-03). Version remplacée par [ADR-0017](0017-baseline-versions-2026.md) (16 → 18).
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

Le sous-système financier a besoin de contraintes fortes, de verrous de ligne, de
triggers de contrôle et de contraintes d'exclusion.

## Décision

- PostgreSQL est le seul SGBD, en développement, en CI **et dans les tests** :
  `phpunit.xml` pointe `core_testing` sur PostgreSQL, jamais SQLite.
- Les migrations peuvent utiliser du SQL propre à PostgreSQL (`text[]`,
  contraintes `CHECK`, triggers). C'est assumé.

## Alternatives écartées

- **SQLite en mémoire pour les tests** : plus rapide, mais incapable de reproduire
  `SELECT … FOR UPDATE`, les triggers différés ou `information_schema`. Un test
  vert sur SQLite ne prouverait rien sur les invariants financiers.
- **MySQL** : verrous et contraintes moins expressifs pour ce besoin.

## Conséquences

- Les suites `schema`, `invariants` et `integration` exigent la stack Docker
  (ou les services PostgreSQL de la CI).
- Portabilité SGBD abandonnée volontairement.
