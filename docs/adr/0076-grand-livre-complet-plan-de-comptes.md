# ADR-0076 — Le grand livre complet : chaque somme est écrite, dans un plan de comptes par projet

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D6 intégrité financière · D2 couplage
- **Amende** : ADR-0037 (un paiement sans crédit de wallet produit désormais une écriture), ADR-0033 (comptes système)

## Contexte

Le grand livre livré est solide **en base** : double entrée, somme nulle
vérifiée par trigger différé, écritures immuables par trigger, soldes
matérialisés, idempotence par `(project_id, idempotency_key)` (ADR-0005,
ADR-0033).

Mais il ne voit qu'une **partie** de l'argent. Vérifié dans le code :

- `HandlePaymentStatusChangedUseCase` n'écrit au grand livre **que** si le
  paiement porte `wallet_recipient_external_ref` (via `CreditWallet` : débit
  `clearing`, crédit `wallet`). Un paiement réussi sans wallet ne laisse
  **aucune** écriture.
- Les comptes système sont trois : `clearing` (au niveau projet), `wallet`,
  `withdrawal`. Il n'y a ni créance client, ni revenu, ni compte d'attente.
- Le seul port de lecture est **par compte** (`ListAccountEntries`) : aucune
  lecture d'un projet sur une période, aucune balance.

Un grand livre qui ne voit pas tout ne peut être ni rapproché ni cru.

## Décision

### 1. Règle d'or : toute somme qui entre ou sort est écrite

Tout paiement confirmé, toute facture émise, tout encaissement hors ligne,
tout versement, toute correction produit une transaction équilibrée. Le
contrôle d'invariant quotidien (ADR-0078) vérifie qu'**aucun** paiement
`succeeded` n'est sans transaction.

### 2. Plan de comptes par projet et par devise

Les comptes restent cloisonnés par `(project_id, kind, reference, currency)`.
Les **types** sont un registre fermé du module `wallet`/`billing`/`payout`
(selon le propriétaire), documenté ici :

| Type (`kind`) | Référence | Nature | Rôle |
|---|---|---|---|
| `clearing` | code prestataire | actif | ce que le prestataire détient pour le projet (créance sur le prestataire) |
| `collections` | — | passif d'attente | encaissements reçus, **en attente d'imputation** (facture) — doit tendre vers zéro |
| `payment_exceptions` | — | passif d'attente | encaissements reçus mais **non imputables** (écart de montant, surpaiement) |
| `customer_receivable` | id client | actif | ce que le client doit (factures émises non payées) |
| `revenue` | — | produit | revenu du projet (factures émises, encaissements directs) |
| `wallet` | id partie | passif | soldes détenus pour des parties (existant) |
| `manual_receipts` | moyen | actif | encaissements hors ligne en attente de dépôt |
| `payout_in_transit` | code prestataire | actif | versements envoyés, non confirmés (ADR-0079) |
| `withdrawal` | — | contrepartie | sorties enregistrées (existant, ADR-0065) |
| `provider_fees` | code prestataire | charge | frais du prestataire, quand le relevé les donne (ADR-0078) |
| `bad_debt` | — | charge | créances abandonnées |
| `adjustment` | — | contrepartie | corrections d'exploitation motivées (double validation) |

Le compte `clearing` historique (référence vide) est conservé ; les nouvelles
écritures utilisent `clearing:<prestataire>` pour permettre le rapprochement par
prestataire. Une écriture de reclassement unique, motivée et auditée, solde
l'ancien compte vers le nouveau au déploiement.

### 3. L'intention de l'encaissement décide de l'écriture

Le module `payment` porte un champ interne `purpose` sur le paiement :

| `purpose` | Qui le fixe | Écriture à la confirmation |
|---|---|---|
| `wallet_credit` | l'appelant, en fournissant `wallet_recipient_external_ref` (comportement existant, ADR-0037) | Débit `clearing:<p>` / Crédit `wallet:<partie>` |
| `invoice` | `billing` (ADR-0073) | Débit `clearing:<p>` / Crédit `collections` — puis `billing` impute |
| `direct` | défaut d'un paiement `/v1/payments/*` sans wallet | Débit `clearing:<p>` / Crédit `revenue` |

L'écriture est faite **dans la même transaction** que le passage du paiement à
`succeeded` (comme le crédit de wallet aujourd'hui). Aucun comportement public
ne change : le paiement direct reste « un encaissement au nom du projet », il
est simplement **compté**.

### 4. Le grand livre se lit par projet et par période

Nouveaux ports dans `ledger/Contracts/` (lecture seule, `ledger` ne dépend
toujours de rien — G2) :

- `ListLedgerTransactions(projectId, from, to, cursor, limit)` ;
- `TrialBalance(projectId, currency, at)` — solde de chaque compte à une date ;
- `AccountStatement(accountRef, from, to, cursor)` — relevé avec solde
  d'ouverture et de clôture.

Pagination par curseur `(created_at, id)`, index adaptés.

### 5. Invariants en base, pas seulement dans le code

- Existant : somme nulle par transaction (trigger différé), immuabilité des
  écritures (trigger), montants `> 0`.
- **Nouveau** : un trigger refuse une écriture dont le compte n'appartient pas
  au **même projet** que la transaction (`ledger_entries.account_id →
  ledger_accounts.project_id = ledger_transactions.project_id`). Un mouvement
  entre deux projets devient **impossible en base**, quel que soit le code
  appelant (garde-fou G13).
- **Nouveau** : `ledger_transactions` immuable (même trigger que les
  écritures) ; une correction est une transaction de contre-passation qui
  référence l'originale (`reverses_transaction_id`).

### 6. Corrections d'exploitation

Une correction (`adjustment`) passe par la console : montant, comptes, motif,
**double validation** (un second compte console `org_finance` ou plateforme
approuve). Elle est auditée et produit une transaction normale. Aucune
correction n'altère une écriture existante.

## Alternatives écartées

- **Écrire seulement les flux qui touchent un wallet (état actuel).** Rejeté :
  la majorité des encaissements d'abonnement serait invisible ; aucun
  rapprochement possible.
- **Un plan de comptes configurable par projet.** Rejeté : chaque projet
  inventerait ses comptes ; les rapports et contrôles ne seraient plus
  génériques. Le registre fermé et la `reference` suffisent.
- **Comptabilité générale complète (journal, grand livre, bilan de
  l'organisation).** Rejeté : le core tient le sous-grand-livre des flux qu'il
  voit ; il exporte vers l'outil comptable de l'organisation (ADR-0088).
- **Isolation inter-projets seulement dans le code.** Rejeté : un seul bogue
  suffit ; le trigger rend l'erreur impossible.

## Conséquences

- `payment` écrit au grand livre pour **tout** paiement confirmé (il en a déjà
  la dépendance).
- `billing` écrit émission, imputation, encaissement hors ligne,
  irrécouvrable.
- Le solde de `collections` devient un indicateur de santé (alerte si une
  somme y reste plus de N heures) ; celui de `payment_exceptions` aussi.
- La console affiche balance, relevés et revenu **lus dans le grand livre**
  (ADR-0080).

## Vérification

- Test d'invariant : chaque paiement `succeeded` a exactement une transaction
  de règlement, de clé d'idempotence = id du paiement (la même que celle du
  crédit de wallet : un seul règlement par paiement, quel que soit le chemin).
- Test du trigger inter-projets : une écriture vers un compte d'un autre projet
  → erreur PostgreSQL.
- Test : balance de vérification d'un projet = 0 par devise.
- Test de non-régression : le crédit de wallet existant produit les mêmes
  écritures qu'avant (au compte `clearing:<p>` près).
