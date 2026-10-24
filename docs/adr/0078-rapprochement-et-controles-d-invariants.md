# ADR-0078 — Rapprochement et contrôles d'invariants : le grand livre confronté au réel

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D6 intégrité financière · D4 provider · D7 organisationnelle
- **Complète** : ADR-0067 (réconciliation de statut des paiements bloqués — inchangée)

## Contexte

Le core sait recontacter un prestataire pour un paiement bloqué (ADR-0067 :
réconciliation **de statut**). Il ne sait pas répondre à :

- « tout ce que le prestataire dit avoir encaissé pour ce projet est-il au
  grand livre, et inversement ? »
- « le solde réel du compte du projet chez le prestataire correspond-il au
  solde `clearing` ? »
- « nos propres invariants tiennent-ils encore ? »

Vérifié : l'adaptateur MyCoolPay n'implémente aujourd'hui ni relevé de
transactions ni solde ; le port `PaymentGateway` expose `initiate`,
`submitAction`, `checkStatus`. Le rapprochement doit donc être conçu par
**capacités d'adaptateur**, et fonctionner partiellement tant qu'une capacité
manque.

## Décision

### 1. Trois niveaux de contrôle, indépendants

| Niveau | Source | Fréquence | Capacité d'adaptateur requise |
|---|---|---|---|
| **A. Invariants internes** | le core seul | chaque heure | aucune |
| **B. Rapprochement transactionnel** | relevé du prestataire ↔ transactions `clearing:<p>` | à la clôture de chaque cycle du prestataire (défaut quotidien) | `listTransactions(from, to)` |
| **C. Rapprochement de solde** | solde du compte du projet chez le prestataire ↔ solde `clearing:<p>` | quotidien | `balance()` |

Le niveau A fonctionne dès le premier jour. B et C s'activent par prestataire
dès que l'adaptateur déclare la capacité. Un prestataire sans capacité est
signalé « non rapproché » dans la console — jamais présumé juste.

### 2. Niveau A — les invariants contrôlés

1. Balance de vérification = 0 par projet et par devise.
2. Chaque paiement `succeeded` a sa transaction de règlement ; aucune
   transaction de règlement sans paiement `succeeded`.
3. Σ `customer_receivable` = Σ restant dû des factures ouvertes, par projet.
4. Σ soldes `wallet` d'un projet ≤ Σ entrées `clearing` + `manual_receipts`
   du projet (aucune valeur créée).
5. `collections` : aucune somme plus vieille que N heures (défaut 24 h).
6. Compteurs d'usage = Σ du journal pour la fenêtre (ADR-0074).
7. Chaque facture `paid` a des imputations dont la somme couvre le total.

Une violation ouvre une exception `critical` (ADR-0077) et une alerte.

**Chaque invariant est vérifié par le module qui possède ses données**, pour ne
créer aucune dépendance nouvelle : `payment` vérifie 1, 2 et 4 (il dépend
déjà de `ledger` et `wallet`) ; `billing` vérifie 3, 6 et 7 (il dépend de
`ledger` et `payment`) ; `notification` n'a rien à vérifier. Les violations
sont déposées dans la file `money_exceptions` par
`payment/Contracts\RecordMoneyException`. Une seule commande planifiée de
l'application (`invariants:check`) appelle les contrôleurs des deux modules.
`payment` ne dépend jamais de `billing` (ce serait un cycle).

### 3. Niveau B — rapprochement transactionnel

- **Périmètre** : uniquement les transactions adossées à une transaction du
  prestataire (règlements de paiements, versements). Transferts, retraits
  enregistrés, imputations et corrections sont **hors périmètre** par
  construction — ce sont des mouvements internes.
- **Clé de jointure** : `payments.provider_transaction_ref` ↔ référence du
  relevé ; transaction du grand livre ↔ paiement par `payment:<id>:settle`.
- **Normalisation avant comparaison** : brut/net, frais
  (écriture `provider_fees` quand le relevé les donne), date de valeur contre
  date de règlement, fuseau.
- **Fenêtre** : le cycle du prestataire et le précédent (tolérance de
  décalage paramétrée), jamais le seul jour civil.
- **Écarts** : présence (une seule des deux sources), montant, statut. Chaque
  écart devient une exception `reconciliation_gap` avec sa sévérité
  (montant/présence = `high`, statut = `medium`).

### 4. Niveau C — rapprochement de solde

`solde prestataire(projet, prestataire, t)` comparé à
`solde clearing:<prestataire>(t) − Σ payout_in_transit`. Un écart supérieur à la
tolérance paramétrée ouvre une exception `critical`.

### 5. Exécution et traçabilité

```sql
CREATE TABLE reconciliation_runs (
  id            uuid PRIMARY KEY,
  project_id    uuid NOT NULL,
  provider_code text,                -- NULL pour le niveau A
  level         text NOT NULL,       -- 'invariants' | 'transactions' | 'balance'
  window_start  timestamptz,
  window_end    timestamptz,
  status        text NOT NULL,       -- 'running' | 'completed' | 'failed'
  stats         jsonb NOT NULL DEFAULT '{}',  -- comptés, appariés, écarts
  started_at    timestamptz NOT NULL DEFAULT now(),
  finished_at   timestamptz
);
```

- Commande planifiée par niveau (`onOneServer`, `withoutOverlapping`) et
  déclenchement manuel depuis la console (`org_finance`).
- Un rapprochement **constate** ; il n'écrit jamais au grand livre. La
  correction d'un écart passe par la résolution d'exception (ADR-0077 §4).
- Paramètres (tolérances, cycles, seuils de sévérité) par prestataire, en
  configuration — aucun seuil codé en dur.

### 6. Accès

Rapport et exceptions : console uniquement, permission
`reconciliation:read` / `reconciliation:write`, portée par organisation
(ADR-0069). Aucune route publique.

## Alternatives écartées

- **Attendre que tous les prestataires exposent relevé et solde.** Rejeté : les
  invariants internes détectent déjà les bogues les plus graves (comme un
  paiement réussi sans écriture).
- **Rapprocher par totaux journaliers.** Rejeté : les totaux masquent les
  écarts qui se compensent.
- **Corriger automatiquement les écarts.** Rejeté : une correction est une
  décision financière ; elle passe par une personne et une double validation.

## Conséquences

- Le port `PaymentGateway` gagne deux capacités **optionnelles**
  (`listTransactions`, `balance`), déclarées par adaptateur.
- La console affiche, par projet et par prestataire, l'état du dernier
  rapprochement et le nombre d'écarts ouverts.
- L'adaptateur MyCoolPay implémente `balance` et `listTransactions` dès que
  le prestataire les documente pour l'application du projet.

## Vérification

- Tests du niveau A avec données corrompues volontairement (paiement réussi
  sans écriture, wallet gonflé) → exception `critical`.
- Test du niveau B avec un relevé simulé contenant une transaction absente et
  un montant différent → deux exceptions, aucune écriture.
- Test : un transfert interne n'apparaît jamais comme écart.
