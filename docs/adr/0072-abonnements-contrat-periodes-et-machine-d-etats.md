# ADR-0072 — Les abonnements : un contrat, des périodes, une machine d'états

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D5 contrat · D6 intégrité financière · D7 organisationnelle

## Contexte

Un abonnement est un **contrat** entre un client et un projet, qui se
renouvelle par **périodes**. Les cas réels à couvrir dès la v1 :

- essai gratuit accordé à la création d'un client ;
- paiement de la première période, puis renouvellements ;
- **paiement anticipé** du renouvellement (le client paie avant l'échéance) ;
- impayé avec délai de grâce ;
- suspension et reprise par l'exploitant, mois offert, annulation immédiate ou
  à échéance ;
- changement de plan.

Le modèle « un nouvel abonnement par période » casse au premier paiement
anticipé (deux abonnements actifs se chevauchent) et éparpille l'historique.

## Décision

### 1. Le contrat et ses périodes sont deux tables

```sql
CREATE TABLE subscriptions (
  id                      uuid PRIMARY KEY,
  project_id              uuid NOT NULL,
  customer_id             uuid NOT NULL REFERENCES customers(id),
  plan_id                 uuid NOT NULL REFERENCES plans(id),
  plan_version_id         uuid NOT NULL REFERENCES plan_versions(id),
  status                  text NOT NULL,
  price_minor             bigint NOT NULL,      -- figé depuis la version
  currency                char(3) NOT NULL,
  interval_unit           text NOT NULL,
  interval_count          integer NOT NULL,
  anchor_at               timestamptz NOT NULL, -- ancre de calcul des périodes
  current_period_id       uuid,
  trial_ends_at           timestamptz,
  cancel_at_period_end    boolean NOT NULL DEFAULT false,
  pending_plan_version_id uuid,                 -- changement prévu à l'échéance
  paused_at               timestamptz,
  canceled_at             timestamptz,
  ended_at                timestamptz,
  version                 integer NOT NULL DEFAULT 1,
  created_at              timestamptz NOT NULL DEFAULT now(),
  updated_at              timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT subscriptions_status_check CHECK (status IN
    ('incomplete', 'trialing', 'active', 'past_due', 'paused', 'canceled', 'expired'))
);
-- un seul contrat vivant par client et par plan
CREATE UNIQUE INDEX subscriptions_live_unique ON subscriptions (customer_id, plan_id)
  WHERE status IN ('incomplete', 'trialing', 'active', 'past_due', 'paused');
CREATE INDEX subscriptions_project_status_idx ON subscriptions (project_id, status);

CREATE TABLE subscription_periods (
  id              uuid PRIMARY KEY,
  subscription_id uuid NOT NULL REFERENCES subscriptions(id),
  project_id      uuid NOT NULL,
  seq             integer NOT NULL,
  kind            text NOT NULL,     -- 'trial' | 'paid' | 'complimentary'
  starts_at       timestamptz NOT NULL,
  ends_at         timestamptz NOT NULL,
  state           text NOT NULL,     -- 'upcoming' | 'current' | 'closed'
  invoice_id      uuid,              -- facture de la période (kind = 'paid')
  reason          text,              -- obligatoire pour 'complimentary'
  created_at      timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT subscription_periods_kind_check CHECK (kind IN ('trial', 'paid', 'complimentary')),
  CONSTRAINT subscription_periods_state_check CHECK (state IN ('upcoming', 'current', 'closed')),
  CONSTRAINT subscription_periods_range_check CHECK (ends_at > starts_at),
  CONSTRAINT subscription_periods_reason_check CHECK (kind <> 'complimentary' OR reason IS NOT NULL),
  EXCLUDE USING gist (subscription_id WITH =, tstzrange(starts_at, ends_at) WITH &&)
);
CREATE UNIQUE INDEX subscription_periods_seq_unique ON subscription_periods (subscription_id, seq);
CREATE UNIQUE INDEX subscription_periods_one_current ON subscription_periods (subscription_id) WHERE state = 'current';
```

La contrainte d'**exclusion** (`btree_gist`) rend tout chevauchement de
périodes impossible **en base**. Une seule période `current` par contrat.

### 2. La machine d'états

| De | Vers | Déclencheur |
|---|---|---|
| — | `incomplete` | création d'un abonnement payant sans essai : la première facture est émise |
| — | `trialing` | création avec essai (`trial_days` du plan ou de l'octroi) |
| `incomplete` | `active` | première facture payée |
| `incomplete` | `expired` | facture non payée après `incomplete_expiry_hours` (défaut 72 h) |
| `trialing` | `active` | fin d'essai **et** facture de la première période payée |
| `trialing` | `past_due` | fin d'essai, facture ouverte, `grace_days > 0` |
| `trialing` | `expired` | fin d'essai, facture ouverte, `grace_days = 0` |
| `active` | `past_due` | fin de période, facture de renouvellement ouverte |
| `past_due` | `active` | facture de renouvellement payée |
| `past_due` | `expired` | fin du délai de grâce sans paiement |
| `active` · `past_due` | `paused` | décision de l'exploitant (motif) |
| `paused` | `active` | reprise : la période courante est **prolongée** de la durée de pause |
| tout état non final | `canceled` | annulation immédiate (client, projet ou exploitant) |
| `active` | `canceled` | à l'échéance, si `cancel_at_period_end` |

`canceled` et `expired` sont **finaux**. Toute transition est un `UPDATE …
WHERE id = ? AND status = :attendu AND version = :v` : zéro ligne →
`409 invalid-state-transition` ou rejeu idempotent.

### 3. Les droits suivent l'état, pas l'inverse

Un client a les droits de sa version de plan si l'abonnement est `trialing`,
`active`, ou `past_due` **et** `grace_access = true`. Dans tout autre état —
ou si le client est `suspended` — aucun droit (ADR-0074).

### 4. Renouvellement : la période suivante existe avant l'échéance

- À `ends_at − renewal_lead_days` (réglage du projet, défaut 7 j), le
  planificateur crée la **période suivante** (`upcoming`) et sa **facture**
  (`open`, échéance = `ends_at`), et émet `subscription.renewal_due`.
- Le client peut payer dès cet instant : **le paiement anticipé ne crée rien
  de nouveau**, il solde la facture de la période `upcoming`.
- À `ends_at` : la période suivante devient `current`. Si sa facture est payée,
  le contrat reste `active` ; sinon il passe `past_due`.
- Aucun chevauchement possible (contrainte d'exclusion), aucun jour perdu.

### 5. Changement de plan

- **À l'échéance (défaut)** : `pending_plan_version_id` ; la période suivante
  et sa facture sont créées avec la nouvelle version.
- **Immédiat** (option, réservée au projet ou à l'exploitant) : la période
  courante est close maintenant, une nouvelle période commence avec la nouvelle
  version et une nouvelle facture. **Sans prorata en v1** : la décision est
  affichée et confirmée. Le prorata est un point d'extension (ADR-0090).
- Migration en masse vers une nouvelle version : action console, par lot,
  auditée, appliquée **à l'échéance** de chaque contrat.

### 6. Les gestes de l'exploitant sont des actes audités

Sous la console (`org_admin`, `org_support` selon l'action, ADR-0069) :
`pause`, `resume`, `cancel` (immédiat ou à échéance), `grant_complimentary`
(ajoute une période `complimentary` de N jours, sans facture, **motif
obligatoire**), `change_plan`. Le « mois offert » est donc une période
explicite, visible et auditable — jamais un paiement fictif.

### 7. API

| Route | Portée | Effet |
|---|---|---|
| `POST /v1/subscriptions` | `subscriptions:write` + `Idempotency-Key` | `{customer_ref, plan_code, trial_days?, start_at?}` → `trialing` ou `incomplete` (+ facture) |
| `GET /v1/subscriptions/{id}` · `GET /v1/subscriptions?customer_ref=&status=&cursor=` | `subscriptions:read` | état, période courante, prochaine échéance |
| `POST /v1/subscriptions/{id}/cancel` | `subscriptions:write` | `{at_period_end: bool}` |
| `POST /v1/subscriptions/{id}/change-plan` | `subscriptions:write` | `{plan_code, effective: "period_end" \| "immediate"}` |

### 8. Le temps est injecté

Tout calcul de date passe par le port `Core\Support\Clock`. Le planificateur
(`billing:advance-subscriptions`, toutes les 5 minutes, `onOneServer`,
`withoutOverlapping`, lots `FOR UPDATE SKIP LOCKED`) est idempotent : rejoué,
il ne crée ni période ni facture en double (unicité `(subscription_id, seq)`).
Les horloges de test (ADR-0087) permettent de dérouler un cycle complet en
staging sans attendre.

### 9. Événements

`subscription.created`, `subscription.trial_will_end` (J−3),
`subscription.activated`, `subscription.renewal_due`, `subscription.renewed`,
`subscription.past_due`, `subscription.paused`, `subscription.resumed`,
`subscription.plan_changed`, `subscription.canceled`, `subscription.expired`.
Chaque charge utile porte `subscription_id`, `customer_ref`, `status`,
`current_period {starts_at, ends_at}` et `version` (ADR-0084 §4).

## Alternatives écartées

- **Un nouvel abonnement par période.** Rejeté : chevauchements au paiement
  anticipé, historique éparpillé, index « un actif par client » qui casse.
- **Renouvellement automatique par prélèvement.** Rejeté en v1 : le mobile
  money est initié par le payeur. Le modèle n'empêche pas un futur moyen de
  paiement récurrent (point d'extension : `collection_method`).
- **Activation sans paiement par l'exploitant.** Rejeté : remplacé par la
  période `complimentary` (explicite, motivée) ou l'encaissement hors ligne
  (ADR-0073), qui laissent une trace comptable juste.

## Conséquences

- `billing` porte deux agrégats (`Subscription`, `SubscriptionPeriod`) et un
  planificateur.
- La première facture d'un abonnement payant est créée **dans la même
  transaction** que l'abonnement (ADR-0073).
- Le statut `past_due` et le délai de grâce rendent l'impayé visible et borné.

## Vérification

- Tests de la table de transitions (une par ligne), y compris les rejeux.
- Test : paiement anticipé → aucune période perdue, aucun chevauchement.
- Test de concurrence : deux renouvellements simultanés → une seule période.
- Recette sur horloge de test : essai → actif → impayé → grâce → expiré.
