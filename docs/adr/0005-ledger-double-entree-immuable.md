# ADR-0005 — Ledger double-entrée immuable, solde matérialisé

- **Statut** : Accepté (spécification technique v1.0, ADR-05)
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

Le ledger est la source de vérité financière. Il doit être auditable,
reproductible, et ne jamais « corriger » le passé.

## Décision

- `ledger_transactions` et `ledger_entries` (spécification technique §6.4).
  Une écriture a une direction (`debit`/`credit`) et un `amount_minor > 0`.
- Par transaction, somme des débits = somme des crédits. Vérifié applicativement
  dans la transaction **et** par un trigger de contrôle différé en défense en
  profondeur.
- Les `ledger_entries` ne sont **jamais** mises à jour ni supprimées : une
  correction est une transaction compensatoire.
- Le solde (`wallet_balances`) est une vue matérialisée mise à jour dans la même
  transaction que les écritures, jamais un champ modifié librement.
- Invariants vérifiés en CI — garde-fou **G11** (`tests/Invariants/`).

## Alternatives écartées

- **Solde calculé à la volée** : exact mais coûteux à chaque autorisation de débit.
- **Solde modifiable sans écriture** : rend l'audit impossible.

## Conséquences

- Definition of Done du Lot 2 : invariants G11 verts, y compris sous concurrence.
- Proposition à valider au Lot 2 : retirer `UPDATE`/`DELETE` sur `ledger_entries`
  au rôle PostgreSQL applicatif, pour que l'immuabilité ne repose pas sur le code.
