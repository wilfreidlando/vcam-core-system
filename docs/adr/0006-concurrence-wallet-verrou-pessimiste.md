# ADR-0006 — Concurrence wallet : verrou pessimiste et append atomique

- **Statut** : Accepté (spécification technique v1.0, ADR-06)
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

Deux débits simultanés lisant un solde de 100 et autorisant chacun 80 laissent le
wallet à −60. C'est le défaut le plus coûteux possible pour un wallet.

## Décision

Toute mutation de wallet s'exécute dans **une** transaction PostgreSQL :

1. `SELECT … FROM wallet_balances WHERE wallet_id = ? FOR UPDATE`
2. vérification de la disponibilité ;
3. insertion des écritures équilibrées ;
4. mise à jour du solde et incrément de `version` ;
5. écriture de l'événement dans l'outbox ;
6. `COMMIT`.

Les opérations sur des wallets différents restent parallèles. Un hold déplace un
montant de `available_minor` vers `held_minor` par le même mécanisme.

**Précision ajoutée** : une opération qui verrouille deux wallets (transfert) les
verrouille dans un ordre déterministe (`wallet_id` croissant) pour éviter les
interblocages.

## Alternatives écartées

- **Verrou optimiste d'emblée** : retries applicatifs et complexité sans contention
  mesurée. La colonne `version` permet d'y basculer si `FOR UPDATE` devient un
  point chaud **mesuré**.

## Conséquences

- Test de concurrence réel (processus parallèles sur PostgreSQL) — G11.
