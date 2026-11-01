# ADR-0074 — Mesure d'usage et quotas : enregistrement idempotent, compteur atomique, refus avant l'effet

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D1 périmètre · D2 couplage · D5 contrat · D6 intégrité financière
- **Modifie le graphe** : `notification → billing` (comité, ADR-0002)

## Contexte

Un plan limite des usages (ADR-0071 §3) : messages envoyés, élèves actifs,
appels d'API, documents générés. Le dispositif transitoire actuel est un
compteur décrémenté à la main par des appels dédiés : non atomique, non
idempotent, contournable, et incapable de **refuser**.

Deux sortes d'usage existent :

- l'usage **produit par le core** lui-même (une notification envoyée) ;
- l'usage **produit par le projet** dans son propre domaine (un élève inscrit),
  que seul le projet peut constater.

Fait vérifié dans le code : `NotificationSendCascade` crée la notification en
base (statut `queued`, clé d'idempotence unique) **avant** tout appel au
prestataire, puis appelle les prestataires hors transaction. Le décompte peut
donc être dans **la même transaction que la création de la notification** —
l'appel externe reste après le commit.

## Décision

### 1. Deux tables : un journal append-only et un compteur

```sql
CREATE TABLE usage_records (
  id              uuid NOT NULL,
  project_id      uuid NOT NULL,
  customer_id     uuid,                    -- NULL = usage imputé au projet lui-même
  meter           text NOT NULL,
  quantity        bigint NOT NULL,         -- négatif = compensation explicite
  idempotency_key text NOT NULL,
  source          text NOT NULL,           -- 'api' | 'notification' | 'console' | ...
  occurred_at     timestamptz NOT NULL,
  recorded_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id, recorded_at),
  CONSTRAINT usage_records_quantity_check CHECK (quantity <> 0)
) PARTITION BY RANGE (recorded_at);          -- partitions mensuelles (ADR-0090)
CREATE UNIQUE INDEX usage_records_idem ON usage_records (project_id, meter, idempotency_key, recorded_at);
-- l'unicité stricte, indépendante de la partition, est portée par usage_idempotency :

CREATE TABLE usage_idempotency (
  project_id      uuid NOT NULL,
  meter           text NOT NULL,
  idempotency_key text NOT NULL,
  usage_record_id uuid NOT NULL,
  outcome         text NOT NULL,           -- 'admitted'
  created_at      timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (project_id, meter, idempotency_key)
);

CREATE TABLE usage_counters (
  id              uuid PRIMARY KEY,
  project_id      uuid NOT NULL,
  customer_id     uuid,
  meter           text NOT NULL,
  window_start    timestamptz,             -- NULL pour une jauge (reset = 'never')
  window_end      timestamptz,
  limit_value     bigint,                  -- NULL = illimité ; figé à l'ouverture de la fenêtre
  overage         text NOT NULL DEFAULT 'block',
  threshold_bps   integer,
  used            bigint NOT NULL DEFAULT 0,
  threshold_notified_at timestamptz,
  limit_notified_at     timestamptz,
  updated_at      timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT usage_counters_used_check CHECK (used >= 0),
  CONSTRAINT usage_counters_overage_check CHECK (overage IN ('block', 'allow'))
);
CREATE UNIQUE INDEX usage_counters_customer_window ON usage_counters
  (project_id, customer_id, meter, COALESCE(window_start, 'epoch'::timestamptz))
  WHERE customer_id IS NOT NULL;
CREATE UNIQUE INDEX usage_counters_project_window ON usage_counters
  (project_id, meter, COALESCE(window_start, 'epoch'::timestamptz))
  WHERE customer_id IS NULL;
```

Le **journal** est la vérité et l'audit ; le **compteur** est une projection
maintenue dans la même transaction, pour décider en une requête.

### 2. L'algorithme de consommation — une transaction, trois écritures

```
BEGIN
  -- a. idempotence : la même clé ne compte qu'une fois
  INSERT INTO usage_idempotency (...) VALUES (...) ON CONFLICT DO NOTHING
  si aucune ligne insérée → COMMIT et renvoyer la décision déjà prise (rejeu)

  -- b. compteur de la fenêtre courante, créé paresseusement depuis les droits du client
  INSERT INTO usage_counters (...) ON CONFLICT DO NOTHING

  -- c. décompte conditionnel, en un seul statement
  UPDATE usage_counters
     SET used = used + :q, updated_at = now()
   WHERE id = :counter
     AND (limit_value IS NULL OR overage = 'allow' OR used + :q <= limit_value)
  RETURNING used, limit_value, threshold_bps
  0 ligne → ROLLBACK → 429 quota-exceeded (rien n'est écrit, la clé d'idempotence non plus)

  -- d. journal + effet du core (ex. création de la notification `queued`)
  INSERT INTO usage_records (...)
  -- e. seuil franchi ? (ancien = used − q) → outbox : usage.threshold_reached / usage.limit_reached
COMMIT
-- f. seulement après le commit : appel au prestataire (pour une notification)
```

- La vérification et l'écriture sont **dans le même statement** : aucun
  dépassement possible sous concurrence (verrou de ligne pris par `UPDATE`).
- L'événement de seuil est émis **dans la transaction** qui le franchit
  (G8) : pas de travail planifié de détection.
- Une requête refusée n'écrit rien : elle peut être rejouée plus tard avec la
  même clé (après rechargement ou changement de plan).

### 3. Les limites viennent de l'abonnement, figées pour la fenêtre

- La fenêtre (`reset = period`) est la **période courante** de l'abonnement
  (ADR-0072) ; une jauge (`reset = never`) n'a pas de fenêtre.
- `limit_value` est lue dans la version de plan **au moment où la fenêtre est
  ouverte** et ne change plus pendant la fenêtre. Un changement de plan
  immédiat ouvre une nouvelle période, donc une nouvelle fenêtre.
- Aucun droit actif (ADR-0072 §3) → `403 entitlement-inactive`. Fonctionnalité
  absente du plan → `403 feature-not-included`. Compteur sans limite
  déclarée pour un projet qui n'a pas activé la facturation → usage enregistré,
  jamais bloqué (compatibilité des projets existants).

### 4. Usage produit par le projet : une route

`POST /v1/usage` (`usage:write`) :

```json
{ "customer_ref": "AE0001", "meter": "students", "quantity": 1,
  "idempotency_key": "student:8f2c…:enroll", "occurred_at": "2026-11-02T09:12:00Z",
  "mode": "enforce" }
```

- `mode = enforce` : décompte conditionnel (§2), `201` ou `429 quota-exceeded`.
- `mode = record` : enregistré même au-delà de la limite (constat a posteriori).
- Jauge : `quantity` négatif pour libérer (élève désinscrit) — clé
  d'idempotence propre à la libération.
- Lecture : `GET /v1/customers/{ref}/entitlements` (droits + compteurs de la
  fenêtre courante) et `GET /v1/usage?customer_ref=&meter=&from=&to=` (journal).

### 5. Usage produit par le core : un port, pas un appel direct

- `notification` définit dans son `Domain/` le port
  `QuotaGuard::consume(string $projectId, ?string $customerRef, string $meter, int $quantity, string $idempotencyKey): QuotaDecision`
  (scalaires uniquement, G3b). Son adaptateur d'`Infrastructure/` appelle
  `billing/Contracts`. Arête de graphe `notification → billing`.
- Le projet déclare dans `billing_settings.meter_mappings` quelle capacité
  alimente quel compteur (`{"notification.whatsapp": "whatsapp_messages"}`).
  Sans correspondance, aucun décompte.
- Clé d'idempotence : `notification:<clé d'idempotence de la notification>` —
  un rejeu de la même notification ne compte qu'une fois.
- Une notification qui échoue chez tous les prestataires : selon
  `billing_settings.refund_failed_usage` (défaut `true`), une **compensation**
  (`quantity = −1`, clé `notification:<…>:refund`) est enregistrée au passage
  en `failed`. La cascade de secours (prestataire 1 puis 2) reste **une**
  unité.

### 6. Attribution obligatoire quand la facturation est active

Si `billing_settings.require_customer_ref = true`, un appel à une route
mesurée sans `customer_ref` → `422 customer-ref-required`. Le réglage est
**désactivé par défaut** (aucun projet déjà branché n'est cassé) ; la console
propose de l'activer à la publication du premier plan qui porte une limite, et
la liste de contrôle d'onboarding (ADR-0080 §4) signale un projet facturé où il
reste désactivé. Un projet ne peut donc pas
contourner les limites de ses clients en omettant la référence par erreur.

**Modèle de menace explicite** : le serveur du projet est de confiance pour
l'attribution de ses propres usages ; le core garantit l'atomicité,
l'idempotence et le refus. La protection de la **plateforme** contre un projet
abusif est assurée par la limitation de débit (ADR-0085), pas par les quotas
commerciaux.

## Alternatives écartées

- **Compteur décrémenté à la main par le projet.** Rejeté : non atomique, non
  idempotent, ne refuse rien.
- **Compteur dans Valkey.** Rejeté : perte au redémarrage, pas de transaction
  avec la création de l'effet, pas d'audit.
- **Décompte après l'envoi.** Rejeté : la fenêtre entre l'envoi et le décompte
  est exactement la fenêtre de dépassement.
- **Décompte dans une transaction séparée, avant la création.** Rejeté : un
  plantage entre les deux perd une unité sans trace, et un rejeu compte deux
  fois.
- **Un travail planifié pour détecter les seuils.** Rejeté : retard et double
  envoi ; le franchissement est connu dans la transaction qui le provoque.

## Conséquences

- `billing` expose `Contracts\ConsumeUsage`, `Contracts\GetEntitlements`.
- Le graphe gagne `notification → billing` ; aucun cycle.
- Les compteurs nommés restent des chaînes libres : un nouveau projet mesure
  un nouvel usage **sans modifier le core**.
- La facturation à l'usage (ligne de facture calculée depuis le journal) est
  un point d'extension (ADR-0090) : le journal en est déjà la source.

## Vérification

- Test de concurrence : limite 1, 50 requêtes simultanées → exactement 1
  admise.
- Test : même clé d'idempotence rejouée 10 fois → `used` augmente de 1.
- Test : notification refusée par quota → aucune ligne `notifications`, aucun
  appel prestataire.
- Test : franchissement de seuil → un seul événement, même sous concurrence.
- Invariant : `used` = Σ `quantity` du journal pour la fenêtre (job de contrôle
  quotidien, ADR-0078).
