# ADR-0073 — Factures, encaissement d'une facture et paiements hors ligne

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D5 contrat · D6 intégrité financière
- **Amende** : ADR-0037 (le paiement d'une facture est rattaché par le module `billing`, jamais deviné par `payment`)

## Contexte

Un abonnement produit des **créances** : « le client X doit 15 000 XAF pour la
période du 1ᵉʳ au 30 novembre ». Il faut un objet qui porte cette créance, qui
puisse être payé en ligne (mobile money via le module `payment`), payé hors
ligne (virement, espèces), payé en plusieurs tentatives, annulé, et qui laisse
une trace comptable exacte.

Trois pièges connus :

- une colonne unique `payment_id` sur l'objet payé **perd** la trace d'une
  tentative antérieure qui aboutit tard (l'argent arrive, rien ne s'active) ;
- une activation « manuelle » sans argent fausse la recette ;
- un document recalculé à la volée change quand le catalogue change.

## Décision

### 1. La facture est un document immuable une fois émise

```sql
CREATE TABLE invoices (
  id                 uuid PRIMARY KEY,
  project_id         uuid NOT NULL,
  customer_id        uuid NOT NULL REFERENCES customers(id),
  subscription_id    uuid REFERENCES subscriptions(id),
  period_id          uuid REFERENCES subscription_periods(id),
  number             text,                    -- attribué à l'émission : '<prefix>-<année>-<séquence>'
  status             text NOT NULL DEFAULT 'draft',
  currency           char(3) NOT NULL,
  subtotal_minor     bigint NOT NULL DEFAULT 0,
  tax_minor          bigint NOT NULL DEFAULT 0,
  total_minor        bigint NOT NULL DEFAULT 0,
  amount_paid_minor  bigint NOT NULL DEFAULT 0,
  customer_snapshot  jsonb NOT NULL DEFAULT '{}', -- nom et contact à l'émission, chiffrés (ADR-0083)
  due_at             timestamptz,
  issued_at          timestamptz,
  paid_at            timestamptz,
  voided_at          timestamptz,
  void_reason        text,
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible')),
  CONSTRAINT invoices_amounts_check CHECK (subtotal_minor >= 0 AND tax_minor >= 0
    AND total_minor = subtotal_minor + tax_minor AND amount_paid_minor >= 0),
  CONSTRAINT invoices_number_when_issued CHECK (status = 'draft' OR number IS NOT NULL)
);
CREATE UNIQUE INDEX invoices_project_number_unique ON invoices (project_id, number) WHERE number IS NOT NULL;
CREATE UNIQUE INDEX invoices_one_per_period ON invoices (period_id) WHERE period_id IS NOT NULL AND status <> 'void';

CREATE TABLE invoice_lines (
  id                 uuid PRIMARY KEY,
  invoice_id         uuid NOT NULL REFERENCES invoices(id),
  project_id         uuid NOT NULL,
  kind               text NOT NULL,           -- 'subscription' | 'manual'  (registre extensible : 'usage', ADR-0090)
  description        text NOT NULL,
  quantity           bigint NOT NULL DEFAULT 1,
  unit_amount_minor  bigint NOT NULL,
  amount_minor       bigint NOT NULL,
  tax_rate_bps       integer NOT NULL DEFAULT 0,  -- taux générique, sans logique fiscale
  period_start       timestamptz,
  period_end         timestamptz,
  CONSTRAINT invoice_lines_amount_check CHECK (quantity > 0 AND unit_amount_minor >= 0
    AND amount_minor = quantity * unit_amount_minor),
  CONSTRAINT invoice_lines_tax_check CHECK (tax_rate_bps BETWEEN 0 AND 10000)
);

CREATE TABLE invoice_number_sequences (
  project_id  uuid NOT NULL,
  year        integer NOT NULL,
  next_value  bigint NOT NULL DEFAULT 1,
  PRIMARY KEY (project_id, year)
);
```

- **Émission** (`draft → open`) : numéro attribué sous verrou de ligne de la
  séquence (`SELECT … FOR UPDATE`) — **sans trou** par projet et par année.
  Lignes, montants et `customer_snapshot` deviennent immuables (trigger) ;
  seuls `status`, `amount_paid_minor`, `paid_at`, `voided_at`, `void_reason`
  évoluent ensuite.
- **Taxe** : `tax_rate_bps` est un taux par ligne fourni par le projet ou son
  réglage par défaut. Arrondi au demi supérieur **par ligne**. Le core ne
  connaît aucun régime fiscal : il calcule ce qu'on lui donne.
- La facture d'une période d'abonnement est créée par le planificateur
  (ADR-0072 §4), ou dans la transaction de création de l'abonnement pour la
  première période.
- Factures manuelles : le projet (`invoices:write`) ou la console
  (`org_finance`) peuvent créer une facture libre (lignes `manual`) pour un
  client — frais de mise en service, prestation ponctuelle.

### 2. Payer une facture : une table de rattachement, plusieurs tentatives

```sql
CREATE TABLE invoice_payments (
  id            uuid PRIMARY KEY,
  invoice_id    uuid NOT NULL REFERENCES invoices(id),
  project_id    uuid NOT NULL,
  payment_id    uuid NOT NULL,          -- référence opaque vers `payment` (pas de FK inter-module)
  amount_minor  bigint NOT NULL,
  status        text NOT NULL DEFAULT 'pending',
  created_at    timestamptz NOT NULL DEFAULT now(),
  settled_at    timestamptz,
  CONSTRAINT invoice_payments_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'canceled'))
);
CREATE UNIQUE INDEX invoice_payments_payment_unique ON invoice_payments (payment_id);
CREATE UNIQUE INDEX invoice_payments_one_pending ON invoice_payments (invoice_id) WHERE status = 'pending';
```

- **Initier** : `POST /v1/invoices/{id}/pay` (projet, `invoices:write`,
  `Idempotency-Key`) ou depuis le portail (ADR-0075). `billing` appelle le
  port `Contracts\StartPayment` du module `payment` avec : le `project_id` **de
  la facture**, le montant restant dû, la devise, le canal et le numéro du
  payeur, et l'intention `purpose = invoice` (ADR-0076 §3). Il enregistre
  `invoice_payments` **dans la même transaction** que la création du paiement.
- **Une seule tentative en attente** par facture (index partiel). Une nouvelle
  tentative exige que la précédente soit `failed` ou `canceled`.
- **Constater** : `billing` consomme `payment.status_changed` (outbox) et
  retrouve la tentative **par `payment_id`**. Transition conditionnelle
  `WHERE status = 'pending'` : un rejeu ne fait rien.
- **Paiement tardif** : si une tentative ancienne aboutit alors que la facture
  est déjà payée, le montant n'est **pas perdu** : `billing` le transfère de
  `collections` vers `payment_exceptions` (Débit `collections` / Crédit
  `payment_exceptions`) et ouvre une **exception de surpaiement** (ADR-0077).
  Tout l'argent non imputable est ainsi sur un seul compte. Rien n'est
  silencieux.
- Un paiement dont le montant confirmé diffère du montant demandé n'est
  **jamais** imputé automatiquement (ADR-0077).

### 3. Imputation et écritures

| Événement | Écriture (ADR-0076) | Effet sur la facture |
|---|---|---|
| Émission | Débit `customer_receivable:<client>` / Crédit `revenue` | `open` |
| Paiement en ligne confirmé | (par `payment`) Débit `clearing:<prestataire>` / Crédit `collections` ; puis (par `billing`) Débit `collections` / Crédit `customer_receivable:<client>` | `amount_paid` augmente ; `paid` si soldée |
| Paiement hors ligne enregistré | Débit `manual_receipts:<moyen>` / Crédit `customer_receivable:<client>` | idem |
| Annulation (`void`, seulement si rien n'a été payé) | contre-passation de l'émission | `void` |
| Passage en irrécouvrable | Débit `bad_debt` / Crédit `customer_receivable:<client>` | `uncollectible` |

Chaque écriture porte une clé d'idempotence dérivée de l'objet
(`invoice:<id>:issue`, `invoice_payment:<id>:allocate`…) : un rejeu ne double
jamais une écriture (ADR-0033 §2).

### 4. Paiement hors ligne : la seule « activation manuelle » autorisée

```sql
CREATE TABLE manual_receipts (
  id            uuid PRIMARY KEY,
  project_id    uuid NOT NULL,
  invoice_id    uuid NOT NULL REFERENCES invoices(id),
  method        text NOT NULL,          -- 'bank_transfer' | 'cash' | 'cheque' | 'other'
  reference     text NOT NULL,          -- référence du virement, numéro de reçu…
  amount_minor  bigint NOT NULL CHECK (amount_minor > 0),
  currency      char(3) NOT NULL,
  received_at   timestamptz NOT NULL,
  recorded_by   uuid NOT NULL,          -- compte console
  approved_by   uuid,                   -- second compte si au-dessus du seuil
  evidence_ref  text,                   -- pièce justificative (référence de stockage)
  note          text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT manual_receipts_method_check CHECK (method IN ('bank_transfer', 'cash', 'cheque', 'other')),
  CONSTRAINT manual_receipts_four_eyes CHECK (approved_by IS NULL OR approved_by <> recorded_by)
);
```

- Enregistré par `org_finance` dans la console, audité, avec référence
  obligatoire.
- **Double validation** au-delà d'un seuil réglable par projet
  (`billing_settings.manual_receipt_approval_threshold_minor`) : l'auteur ne
  peut pas valider sa propre saisie (contrainte en base).
- Effet identique à un paiement en ligne : la facture est soldée, la période
  couverte, l'abonnement activé.

### 5. Documents

- **Facture** et **reçu** sont rendus depuis les lignes immuables (HTML
  imprimable ; PDF généré à la demande, non stocké). Personnalisation par
  projet : nom, logo, coordonnées, mentions libres (`billing_settings`).
- Un **reçu** existe pour chaque encaissement imputé (en ligne ou hors ligne) :
  montant, date, référence de transaction du prestataire ou référence de
  l'encaissement hors ligne, facture concernée.

### 6. API et événements

`GET /v1/invoices`, `GET /v1/invoices/{id}`, `GET /v1/invoices/{id}/document`
(`invoices:read`) ; `POST /v1/invoices` (manuelle), `POST /v1/invoices/{id}/issue`,
`/pay`, `/void` (`invoices:write`). Événements : `invoice.created`,
`invoice.issued`, `invoice.paid`, `invoice.payment_failed`, `invoice.voided`,
`invoice.uncollectible`.

## Alternatives écartées

- **`payment_id` unique sur l'abonnement ou la facture.** Rejeté : perte des
  tentatives et des paiements tardifs.
- **Laisser `payment` connaître les factures.** Rejeté : `payment` est
  générique ; le rattachement appartient au module qui possède la créance.
- **Activation manuelle sans écriture.** Rejeté : recette fausse, aucune trace.
  Remplacée par l'encaissement hors ligne et la période offerte (ADR-0072 §6).
- **Recalculer la facture à l'affichage.** Rejeté : un document qui change
  après émission n'est pas un document.
- **Numérotation par séquence PostgreSQL.** Rejeté : une séquence laisse des
  trous à chaque rollback ; la table de séquences sous verrou n'en laisse pas.

## Conséquences

- `billing` dépend de `payment` (déjà autorisé) pour initier un paiement, et
  consomme son événement pour le constater.
- La console finance dispose d'une vue créances (factures ouvertes, en
  retard, irrécouvrables) lue dans le grand livre (ADR-0080).
- Les surpaiements et écarts deviennent des exceptions traitées, pas des
  pertes invisibles.

## Vérification

- Test : deux tentatives, la première aboutit après la seconde → facture
  payée une fois, surpaiement en exception, écritures équilibrées.
- Test de concurrence : deux émissions simultanées → numéros consécutifs, sans
  trou ni doublon.
- Test : saisie hors ligne au-dessus du seuil validée par son auteur → refusée.
- Test d'invariant : Σ `customer_receivable` = Σ (total − payé) des factures
  ouvertes, par projet et devise.
