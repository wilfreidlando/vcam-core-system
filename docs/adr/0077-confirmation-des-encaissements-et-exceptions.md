# ADR-0077 — Confirmation des encaissements : montant vérifié, file d'exceptions

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D4 provider · D6 intégrité financière
- **Amende** : ADR-0038 (callback → statut), ADR-0051 (authentification du callback)

## Contexte

Vérifié dans le code : le callback du prestataire contient
`transaction_amount`, mais ce champ ne sert **qu'au calcul de la signature**
(`MyCoolPayCallbackAuthenticator`). Il n'est jamais comparé au montant du
paiement. Le wallet est crédité de `$payment->amount` — le montant **demandé**.

Si le prestataire confirme un autre montant (paiement partiel, frais déduits,
erreur, falsification en amont de la signature), le core enregistre une somme
qui n'a pas été reçue. C'est une faille d'intégrité.

Par ailleurs, plusieurs situations d'argent « reçu mais non imputable »
existent déjà ou vont exister : surpaiement d'une facture (ADR-0073), paiement
tardif d'une tentative abandonnée, devise inattendue. Aujourd'hui, elles
seraient silencieuses.

## Décision

### 1. Le montant confirmé fait partie de l'événement canonique

L'événement interne de changement de statut porte `confirmedAmountMinor` et
`confirmedCurrency`, renseignés **par l'adaptateur** (G6 : seul l'adaptateur
lit le format du prestataire). Chaque adaptateur déclare la capacité
`confirmsAmount` :

- `true` (cas de MyCoolPay) : le montant confirmé est **obligatoire** ; un
  callback sans montant est rejeté comme malformé ;
- `false` (prestataire qui ne le fournit pas) : le core relit le statut par
  `checkStatus()` ; si le montant reste indisponible, le paiement est marqué
  `amount_verified = false` dans sa chronologie et une alerte de faible
  sévérité est ouverte.

### 2. Un écart n'est jamais imputé automatiquement

À la confirmation (`succeeded`) :

| Situation | Écriture | Imputation |
|---|---|---|
| montant et devise conformes | selon `purpose` (ADR-0076 §3) | normale |
| montant ou devise différents | Débit `clearing:<p>` du **montant confirmé** / Crédit `payment_exceptions` | **aucune** : pas de crédit de wallet, pas d'imputation de facture |

Le paiement passe `succeeded` (l'argent est bien chez le prestataire) avec
`exception = amount_mismatch`. L'événement public `payment.status_changed`
gagne deux champs **additifs** : `confirmed_amount` et `exception`
(nullable). Les intégrateurs qui ne les lisent pas ne sont pas affectés
(ADR-0086).

### 3. Une file d'exceptions unique pour tout l'argent non imputé

```sql
CREATE TABLE money_exceptions (
  id              uuid PRIMARY KEY,
  project_id      uuid NOT NULL,
  kind            text NOT NULL,     -- 'amount_mismatch' | 'currency_mismatch' | 'overpayment'
                                     -- | 'late_payment' | 'unallocated_aging' | 'reconciliation_gap'
  severity        text NOT NULL,     -- 'critical' | 'high' | 'medium' | 'low'
  amount_minor    bigint NOT NULL,
  currency        char(3) NOT NULL,
  payment_id      uuid,
  invoice_id      uuid,
  details         jsonb NOT NULL DEFAULT '{}',
  status          text NOT NULL DEFAULT 'open',   -- 'open' | 'acknowledged' | 'resolved'
  resolution      text,              -- 'allocated' | 'refunded_manually' | 'written_off' | 'false_positive'
  resolved_by     uuid,
  approved_by     uuid,
  created_at      timestamptz NOT NULL DEFAULT now(),
  resolved_at     timestamptz,
  CONSTRAINT money_exceptions_four_eyes CHECK (approved_by IS NULL OR approved_by <> resolved_by)
);
```

Propriétaire : le module `payment` (il constate l'argent). `billing` et le
rapprochement (ADR-0078) y déposent leurs exceptions par `Contracts/`.

### 4. Résoudre une exception = une écriture motivée, à quatre yeux

Actions console (`org_finance`, ou rôle de plateforme `finance_operator`) :

- **imputer à une facture** : action portée par `billing` (propriétaire de la
  facture) — Débit `payment_exceptions` / Crédit `customer_receivable:<client>`
  pour tout ou partie du montant, mise à jour de la facture, puis exception
  marquée résolue par `payment/Contracts\ResolveMoneyException` (`billing →
  payment`, arête existante) ;
- **imputer à un wallet** : action portée par `payment` — Débit
  `payment_exceptions` / Crédit `wallet:<partie>` (via `wallet/Contracts`) ;
- **rembourser hors plateforme** : Débit `payment_exceptions` / Crédit
  `withdrawal`, avec la référence du remboursement ;
- **passer en revenu** : Débit `payment_exceptions` / Crédit `revenue`, motif
  obligatoire.

Au-delà du seuil de double validation du projet, un second compte approuve.
Chaque résolution est auditée et produit une transaction normale (ADR-0076).

### 5. Défense contre le rejeu du callback

Le callback authentifié (IP + signature, ADR-0051) est en plus :

- **idempotent** par `(provider, transaction_ref, status)` : un rejeu
  identique ne produit rien ;
- **monotone** : un statut final ne revient jamais à un statut intermédiaire
  (règle existante, réaffirmée et testée) ;
- **confronté** : en cas de doute (statut final reçu pour un paiement inconnu
  ou d'un autre projet), aucune écriture, alerte `critical`.

### Mise en œuvre (lot 9)

Précisions apportées en réalisant la décision ; elles ne changent pas son
sens.

- **Le bassin doit porter la somme avant qu'on la déplace.** Ouvrir une
  exception n'écrit rien au grand livre : c'est l'écriture de règlement qui
  y dépose la somme (ou rien, si la devise n'est pas supportée). Une
  résolution ne peut donc déplacer que ce que le bassin porte réellement :
  le solde est relu sous la même transaction que le mouvement, et la
  résolution d'une exception sans dépôt est refusée. Sans cette
  vérification, imputer une exception à un wallet créditait une somme
  jamais encaissée.
- **Le bassin est un compte unique par projet et par devise**, pas une
  réserve par exception : deux exceptions du même projet et de la même
  devise se partagent le même solde. Le refus le dit.
- **Une exception résolue peut être rouverte.** L'unicité porte sur les
  exceptions **ouvertes** : une violation d'invariant close et non
  corrigée revient dans la file au passage suivant du contrôle horaire.
- **Une exception peut être imputée à un wallet** : le débit du bassin et
  le crédit du wallet sont écrits par le module `wallet`, le seul à
  connaître le compte du wallet et le seul à tenir son solde.

## Alternatives écartées

- **Faire confiance au montant demandé (état actuel).** Rejeté : le grand
  livre enregistre de l'argent qui n'a pas été reçu.
- **Marquer le paiement `failed` en cas d'écart.** Rejeté : l'argent est chez
  le prestataire ; le déclarer échoué l'effacerait de la vue et du
  rapprochement.
- **Imputer automatiquement le montant confirmé.** Rejeté : un paiement partiel
  d'une facture n'est pas une décision que le core peut prendre seul ; un
  crédit de wallet partiel non plus.

## Conséquences

- Chaque adaptateur de paiement expose le montant confirmé ou déclare qu'il ne
  le peut pas — exigence ajoutée à la skill `nouvel-adaptateur-provider`.
- La console gagne la file d'exceptions, triée par sévérité et ancienneté.
- `PaymentStatusNotification::FIELDS`, les schémas OpenAPI et le test
  `PaymentEventContractTest` sont mis à jour ensemble (ajout additif).

## Vérification

- Test : callback avec `transaction_amount` différent → paiement `succeeded`,
  `exception = amount_mismatch`, aucun crédit de wallet, exception ouverte,
  transaction équilibrée vers `payment_exceptions`.
- Test : rejeu du même callback 5 fois → une seule écriture.
- Test : résolution par le même compte que la saisie au-dessus du seuil →
  refusée.
