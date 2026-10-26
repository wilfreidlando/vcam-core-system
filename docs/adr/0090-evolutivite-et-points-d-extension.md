# ADR-0090 — Évolutivité : tenir la charge, et s'étendre sans modifier le cœur

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D1 périmètre · D2 couplage · D5 contrat · D7 organisationnelle

## Contexte

La plateforme passe de quelques projets internes à un nombre ouvert de projets
et d'organisations, chacun avec des milliers de clients, des événements
d'usage à haute fréquence et des webhooks. Deux risques :

- **la charge** : tables append-only qui grossissent sans limite, jobs
  planifiés qui se chevauchent, relais de l'outbox à la minute qui retarde
  toute réaction ;
- **la rigidité** : chaque besoin nouveau (un moyen de paiement, un type de
  fonctionnalité, un prorata) qui exigerait de toucher le cœur.

## Décision

### 1. Tenir la charge

| Sujet | Règle |
|---|---|
| Tables append-only à fort volume (`usage_records`, `webhook_deliveries`, `audit_log`, puis `ledger_entries` au-delà de 50 M lignes) | partitionnement mensuel par `RANGE` sur la date ; création des partitions à l'avance par job ; rétention par détachement de partition (ADR-0083) |
| Listings | pagination par curseur partout (ADR-0088) ; index couvrant `(project_id, created_at, id)` |
| Relais de l'outbox | réveillé **après chaque commit** qui écrit un événement (job en file), balayage à la minute conservé comme filet ; plusieurs workers via `FOR UPDATE SKIP LOCKED` ; ordre garanti par agrégat, pas global |
| Jobs planifiés | idempotents, `onOneServer`, `withoutOverlapping`, traitement par lots `SKIP LOCKED`, reprise sans effet double |
| Équité entre projets dans les files | files Horizon par classe (`webhooks`, `notifications`, `billing`, `reports`) et plafond de jobs simultanés par projet pour les classes lourdes |
| Lectures lourdes (rapports, instantanés) | sur un réplica en lecture dès qu'il existe ; jamais sur la transaction métier |
| Caches | droits d'un client (`entitlements`) mis en cache 60 s dans Valkey, invalidés par événement de `billing` |
| Montée en charge horizontale | l'application reste sans état (sessions en base/Valkey, fichiers sur stockage objet) : plus d'instances derrière le proxy |

### 2. S'étendre par registres et ports, pas par modifications

| Point d'extension | Mécanisme | Exemple futur |
|---|---|---|
| Prestataire de paiement, de versement, de notification | adaptateur derrière un port, déclaré au registre (ADR-0039) avec ses **capacités** (`confirmsAmount`, `listTransactions`, `balance`, `payouts`) | MTN, Orange en direct ; email ; SMS |
| Type de fonctionnalité de plan | registre des types (`boolean`, `limit`) dans `billing` | `tiered_limit`, `metered_price` |
| Type de ligne de facture | registre (`subscription`, `manual`) | `usage` (facturation à l'usage depuis le journal) |
| Règle de changement de plan | stratégie (`period_end`, `immediate_no_proration`) | `immediate_prorated` |
| Méthode d'encaissement d'un abonnement | `collection_method` (`payer_initiated` en v1) | prélèvement récurrent si un prestataire le permet |
| Événements | catalogue `WebhookEventTypes` + règles d'ajout de l'ADR-0086 | tout nouvel événement est un ajout |
| Comptes du grand livre | registre fermé des `kind` (ADR-0076) | un type nouveau = un ADR |
| Domaine du portail | `portal_settings.custom_domain` | domaine du projet avec certificat automatique |
| Authentification d'intégrations tierces | OAuth 2.0 client credentials (ADR-0082) | applications partenaires d'un projet |
| Facturation des projets par la plateforme | le module `billing` est générique : la plateforme peut être **elle-même** un projet qui vend des plans aux organisations | « plan plateforme » d'une organisation tierce |

Règle : un point d'extension ajouté se fait par **un nouvel adaptateur ou une
nouvelle entrée de registre, avec son ADR** — jamais par une condition
`if ($project === …)` dans le cœur.

### 3. Les frontières permettent d'extraire un module plus tard

Les modules ne communiquent que par `Contracts/` et par l'outbox ; aucune
jointure inter-modules ; aucune clé étrangère inter-modules (ADR-0033 §5).
Un module (par exemple `notification` ou `billing`) peut donc devenir un
service séparé sans changer le contrat public. Ce n'est **pas** un objectif :
c'est une porte gardée ouverte. Le monolithe modulaire reste la cible
(ADR-0001).

### 4. Configuration comme donnée, par projet

Les réglages qui varient par projet (`billing_settings`, `portal_settings`,
`payout_settings`, budgets de débit) sont des tables du module propriétaire,
modifiables depuis la console et auditées — pas des variables
d'environnement. Les variables d'environnement restent pour la plateforme
(secrets, interrupteurs globaux).

## Alternatives écartées

- **Microservices dès maintenant.** Rejeté : coût opérationnel, transactions
  distribuées sur l'argent ; les frontières actuelles suffisent à extraire
  plus tard si un besoin mesuré l'exige.
- **Plugins arbitraires par projet (code chargé dynamiquement).** Rejeté :
  surface d'attaque et perte de contrôle ; les registres typés suffisent.
- **Relais de l'outbox à la minute seulement.** Rejeté : jusqu'à une minute de
  retard sur l'activation d'un abonnement après paiement.

## Conséquences

- Les nouveaux modules sont conçus partitionnables dès leur première
  migration (`usage_records`).
- Le relais de l'outbox gagne un déclenchement après commit.
- La liste des points d'extension est la référence quand une demande arrive :
  si elle n'y est pas, elle passe les quatre portes (gouvernance §4).

## Vérification

- Test de charge du staging avant la mise en service : 200 req/s mixtes
  pendant 15 min dans les objectifs de l'ADR-0085.
- Test : création anticipée des partitions ; insertion au premier jour d'un
  mois sans erreur.
- Test : deux workers du relais ne livrent jamais deux fois le même
  événement.
