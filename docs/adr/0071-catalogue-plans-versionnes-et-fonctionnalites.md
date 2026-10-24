# ADR-0071 — Le catalogue : plans versionnés et fonctionnalités nommées par le projet

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D1 périmètre · D5 contrat · D6 intégrité financière

## Contexte

Un projet SaaS vend des **plans** (« Essentiel », « Pro »…). Un plan dit
**combien** coûte l'accès, **pour quelle durée**, et **à quoi** il donne droit.
Ces trois éléments changent avec le temps (hausse de prix, nouvelle limite)
alors que les abonnements déjà souscrits doivent garder leurs termes.

La plateforme doit rester générique : elle ne sait pas ce qu'est un « élève »
ou une « chambre ». Mais elle doit pouvoir appliquer « 500 élèves maximum » si
le projet le demande.

## Décision

### 1. Un plan est stable, ses versions portent les termes

```sql
CREATE TABLE plans (
  id          uuid PRIMARY KEY,
  project_id  uuid NOT NULL,
  code        text NOT NULL,                 -- stable, choisi par le projet : 'pro'
  name        text NOT NULL,
  description text,
  status      text NOT NULL DEFAULT 'draft',
  visibility  text NOT NULL DEFAULT 'public',-- 'public' : proposé dans le portail ; 'private' : attribué seulement
  sort_order  integer NOT NULL DEFAULT 0,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT plans_status_check CHECK (status IN ('draft', 'active', 'archived')),
  CONSTRAINT plans_visibility_check CHECK (visibility IN ('public', 'private')),
  CONSTRAINT plans_code_format CHECK (code ~ '^[a-z0-9][a-z0-9_-]{0,63}$')
);
CREATE UNIQUE INDEX plans_project_code_unique ON plans (project_id, code);

CREATE TABLE plan_versions (
  id              uuid PRIMARY KEY,
  plan_id         uuid NOT NULL REFERENCES plans(id),
  project_id      uuid NOT NULL,
  version         integer NOT NULL,
  price_minor     bigint NOT NULL,              -- G5
  currency        char(3) NOT NULL,
  interval_unit   text NOT NULL,                -- 'day' | 'week' | 'month' | 'year'
  interval_count  integer NOT NULL,
  trial_days      integer NOT NULL DEFAULT 0,
  grace_days      integer NOT NULL DEFAULT 0,
  grace_access    boolean NOT NULL DEFAULT true,-- droits maintenus pendant le délai de grâce
  features        jsonb NOT NULL DEFAULT '{}',
  status          text NOT NULL DEFAULT 'draft',
  published_at    timestamptz,
  created_at      timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT plan_versions_price_check CHECK (price_minor >= 0),
  CONSTRAINT plan_versions_interval_check CHECK (interval_unit IN ('day', 'week', 'month', 'year') AND interval_count BETWEEN 1 AND 365),
  CONSTRAINT plan_versions_days_check CHECK (trial_days BETWEEN 0 AND 365 AND grace_days BETWEEN 0 AND 90),
  CONSTRAINT plan_versions_status_check CHECK (status IN ('draft', 'published', 'retired')),
  CONSTRAINT plan_versions_features_object CHECK (jsonb_typeof(features) = 'object')
);
CREATE UNIQUE INDEX plan_versions_plan_version_unique ON plan_versions (plan_id, version);
CREATE UNIQUE INDEX plan_versions_one_draft ON plan_versions (plan_id) WHERE status = 'draft';
```

La clé étrangère `plan_versions → plans` est **intra-module** (`billing`), donc
autorisée (ADR-0033 §5 n'interdit que les clés inter-modules).

### 2. Une version publiée est immuable

- Un **trigger** refuse toute modification de `price_minor`, `currency`,
  `interval_*`, `trial_days`, `grace_days`, `grace_access`, `features` sur une
  version `published` ou `retired` (`plan-version-immutable`). Seul le passage
  `published → retired` est permis.
- Changer un prix = **publier une nouvelle version**. Les abonnements existants
  restent sur leur version ; les nouveaux prennent la dernière version publiée.
- Migrer des abonnés vers une nouvelle version est une **action explicite**
  (ADR-0072 §5), jamais un effet de bord de la publication.

### 3. Les fonctionnalités sont nommées par le projet, typées par le core

```json
{
  "api_access":        { "type": "boolean", "enabled": true },
  "students":          { "type": "limit", "meter": "students", "limit": 500,
                         "reset": "never", "overage": "block" },
  "whatsapp_messages": { "type": "limit", "meter": "whatsapp_messages", "limit": 1000,
                         "reset": "period", "overage": "block", "threshold_bps": 8000 }
}
```

| Champ | Valeurs | Sens |
|---|---|---|
| `type` | `boolean` · `limit` | deux types en v1 ; le registre des types est extensible (ADR-0090) |
| `meter` | chaîne libre `^[a-z0-9_.]{1,64}$` | le compteur consommé (ADR-0074) |
| `limit` | entier ≥ 0, ou `null` = illimité | |
| `reset` | `period` (remis à zéro à chaque période d'abonnement) · `never` (jauge : « nombre d'élèves actifs ») | |
| `overage` | `block` (refus au-delà) · `allow` (enregistré, signalé, non bloqué) | |
| `threshold_bps` | 1–10000 | seuil d'alerte en points de base (8000 = 80 %) |

Les clés de fonctionnalité et de compteur sont **validées par format, jamais
par liste** : le core ne connaît aucune fonctionnalité nommée. Les exemples
ci-dessus sont ceux d'un projet, pas du core.

### 4. Arithmétique des périodes, sans ambiguïté

- Les périodes sont calculées dans le **fuseau du projet**
  (`billing_settings.timezone`, défaut `Africa/Douala`), stockées en UTC.
- `month`/`year` : même jour du mois que l'ancre ; si le jour n'existe pas, le
  **dernier jour du mois** (ancre 31 janvier → 28/29 février → 31 mars).
  L'ancre d'origine est conservée, pas la date ajustée.
- `day`/`week` : addition calendaire de n jours dans le fuseau du projet.

### 5. Gestion du catalogue

- API : `GET /v1/plans` (`plans:read`) ; `POST /v1/plans`,
  `POST /v1/plans/{code}/versions`, `POST /v1/plans/{code}/versions/{n}/publish`,
  `POST /v1/plans/{code}/archive` (`plans:write`). Console : mêmes actions pour
  `org_admin` (ADR-0069).
- Un plan `archived` n'accepte plus de nouvel abonnement ; les abonnements en
  cours continuent jusqu'à leur fin.
- **Une seule devise par version** ; un projet peut avoir des plans dans
  plusieurs devises, sans conversion (aucun taux de change dans le core).

### 6. Ce que le catalogue ne fait pas (en v1)

Prorata, remises et codes promo, tarification à l'unité consommée, paliers.
Chacun est un **point d'extension** identifié (ADR-0090) : le modèle de données
ne les empêche pas, il ne les implémente pas. Une remise en v1 = un plan
privé au prix remisé.

## Alternatives écartées

- **Un plan modifiable en place.** Rejeté : le prix affiché, le prix facturé et
  le prix contractuel divergent ; l'historique est perdu.
- **Des fonctionnalités codées en dur (`sms`, `storage`).** Rejeté : chaque
  nouveau projet exigerait une modification du core.
- **Un prix par date d'effet sur le même plan.** Rejeté : plus difficile à lire
  dans l'audit que « version 3 du plan pro ».
- **Le catalogue hors du core.** Rejeté : le portail ne pourrait ni proposer
  un plan, ni vérifier un montant payé.

## Conséquences

- Les abonnements référencent une `plan_version_id` et figent prix, devise et
  intervalle (ADR-0072).
- La console offre un éditeur de plan avec historique des versions.
- Les événements `plan.version_published` et `plan.archived` rejoignent le
  catalogue d'événements.

## Vérification

- Test du trigger : `UPDATE` du prix d'une version publiée → erreur.
- Tests d'arithmétique : ancre au 31, années bissextiles, fuseau du projet.
- Test : clé de fonctionnalité hors format → `422`.
