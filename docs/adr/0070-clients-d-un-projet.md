# ADR-0070 — Les clients d'un projet : identité minimale, déclarée par le projet, chiffrée au repos

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D1 périmètre · D3 données/PII · D5 contrat
- **Amende** : gouvernance §6 (le core détient l'identité minimale des **clients payeurs** des projets, jamais celle des utilisateurs finaux)

## Contexte

Pour vendre un abonnement, émettre une facture, ouvrir un portail ou appliquer
un quota, le core doit savoir **à qui** il a affaire. Aujourd'hui il ne connaît
que des `Party` sans identité (G4) — utiles pour un wallet, insuffisantes pour
une relation commerciale : pas de cycle de vie, pas de contact, pas de
rattachement contractuel.

Le projet, lui, connaît déjà son client (le tenant d'une application de
gestion, un établissement, un particulier abonné). C'est **lui** la source de
vérité de son client ; le core en tient une **copie minimale**, nécessaire à la
facturation et au portail.

## Décision

### 1. Le client est un objet du module `billing`, scopé au projet

```sql
CREATE TABLE customers (
  id                  uuid PRIMARY KEY,
  project_id          uuid NOT NULL,
  external_ref        text NOT NULL,          -- identifiant du client chez le projet (opaque)
  display_name        text NOT NULL,          -- raison sociale ou nom affiché
  email_ciphertext    text,                   -- chiffré (ADR-0083)
  email_bidx          text,                   -- index aveugle HMAC, pour l'unicité et la recherche exacte
  phone_ciphertext    text,                   -- E.164, chiffré
  phone_bidx          text,
  locale              text NOT NULL DEFAULT 'fr',
  metadata            jsonb NOT NULL DEFAULT '{}',
  status              text NOT NULL DEFAULT 'active',
  version             integer NOT NULL DEFAULT 1,   -- verrou optimiste (ETag)
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  erased_at           timestamptz,
  CONSTRAINT customers_status_check CHECK (status IN ('active', 'suspended', 'erased')),
  CONSTRAINT customers_external_ref_format CHECK (external_ref ~ '^[A-Za-z0-9._:-]{1,128}$'),
  CONSTRAINT customers_metadata_object CHECK (jsonb_typeof(metadata) = 'object'),
  CONSTRAINT customers_metadata_size CHECK (pg_column_size(metadata) <= 4096)
);
CREATE UNIQUE INDEX customers_project_ref_unique ON customers (project_id, external_ref);
CREATE UNIQUE INDEX customers_project_email_unique ON customers (project_id, email_bidx) WHERE email_bidx IS NOT NULL;
CREATE INDEX customers_project_phone_idx ON customers (project_id, phone_bidx) WHERE phone_bidx IS NOT NULL;
```

- `external_ref` est **la clé de corrélation** avec le projet. Le core ne lui
  donne aucun sens ; il impose seulement un format sûr (pas d'injection dans
  une URL, un log ou une clé de cache).
- Email et téléphone sont **optionnels** individuellement ; le portail exige
  au moins un contact vérifiable pour la connexion directe (ADR-0075).
- `metadata` : au plus 16 clés, 4 Ko, **aucune donnée personnelle** par
  politique (documentée au guide) — c'est un espace pour des identifiants
  techniques du projet.
- Une `Party` n'est **pas** un client : un client peut avoir un wallet, et
  alors sa `Party` a `external_ref = customer:<id>` (création paresseuse,
  gouvernance §6).

### 2. Le projet déclare ses clients : upsert idempotent par référence

| Route | Portée | Effet |
|---|---|---|
| `PUT /v1/customers/{external_ref}` | `customers:write` | crée (`201`) ou met à jour (`200`) ; idempotent par construction ; `If-Match: <version>` optionnel → `412 version-conflict` si la version a changé |
| `GET /v1/customers/{external_ref}` | `customers:read` | lecture, avec `ETag` |
| `GET /v1/customers?cursor=&limit=` | `customers:read` | liste paginée par curseur (ADR-0088) |
| `POST /v1/customers/{external_ref}/suspend` · `/reactivate` | `customers:write` | statut ; un client suspendu n'a plus de droits (ADR-0072) mais ses factures restent dues |
| `POST /v1/customers/{external_ref}/erase` | `customers:write` | effacement (ADR-0083) |

Le client peut aussi être créé et édité depuis la console (`org_admin`,
`org_support`).

### 3. Événements

`customer.created`, `customer.updated`, `customer.suspended`,
`customer.reactivated`, `customer.erased` — charge utile **sans** donnée
personnelle : `{customer_id, external_ref, status, version}`. Le projet relit
le client par l'API s'il en a besoin.

### 4. Normalisation

Email : minuscules, espaces retirés, validé RFC 5322 simplifié. Téléphone :
E.164 strict (`^\+[1-9][0-9]{7,14}$`). L'index aveugle est calculé sur la
forme normalisée (ADR-0083).

## Sécurité

- **Énumération** : les erreurs ne disent jamais si un email existe dans un
  autre projet ; l'unicité est **par projet**.
- **Exposition** : les réponses d'API ne renvoient email et téléphone qu'avec
  la portée `customers:read` ; les listings console masquent par défaut
  (`a***@d***.cm`), le démasquage est une action auditée.
- **Injection** : `external_ref` et `display_name` sont échappés à
  l'affichage (portail, console, documents) ; `display_name` limité à 200
  caractères, sans caractères de contrôle.

## Alternatives écartées

- **Étendre `parties`.** Rejeté : `parties` est volontairement sans identité
  (G4) et créé paresseusement pour l'argent ; y mettre un cycle de vie
  commercial et une PII mélange deux rôles.
- **Le client dans `identity`.** Rejeté : `identity` authentifie des machines
  et des comptes console ; le client est un objet commercial, sa place est
  avec ses abonnements et ses factures.
- **Aucune identité (référence seule).** Rejeté : pas de portail, pas de
  facture lisible, pas de relance possible.
- **Une `Organization` par client.** Rejeté : une organisation possède des
  projets ; un client achète un projet. Cardinalités inverses.

## Conséquences

- La gouvernance §6 est amendée (voir addendum de `gouvernance-anti-derive.md`).
- Le garde-fou G4 reste inchangé sur `parties` ; `customers` reçoit une **liste
  blanche de colonnes** (test de schéma) : toute nouvelle colonne exige un ADR.
- Les clients sont dans l'export de projet (ADR-0088).

## Vérification

- Test d'isolation : un client du projet A est introuvable depuis le projet B
  (404), y compris par email.
- Test de concurrence : deux `PUT` simultanés sur la même référence → une
  seule ligne.
- Test de schéma : colonnes de `customers` = liste blanche.
