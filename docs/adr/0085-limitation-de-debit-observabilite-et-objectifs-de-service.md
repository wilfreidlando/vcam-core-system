# ADR-0085 — Limitation de débit, observabilité par projet, objectifs de service

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D5 contrat · D7 organisationnelle

## Contexte

Le seul plafond actuel est par adresse IP (ADR-0058) : il protège
l'infrastructure, pas l'équité entre projets. Un projet bogué ou abusif peut
saturer la plateforme pour tous.

L'observabilité est en place et bonne (logs corrélés, traces OTLP,
`/metrics`, pile Alloy/Loki/Tempo/Prometheus/Grafana, ADR-0059/0064) ; il lui
manque la **lecture par projet** et des **objectifs mesurés**.

## Décision

### 1. Limites de débit à plusieurs niveaux

| Niveau | Clé de comptage | Défaut | Réponse |
|---|---|---|---|
| IP non authentifiée | IP | existant (ADR-0058) | `429` |
| Projet | `project_id` | 100 req/s en rafale, 3 000 req/min soutenu | `429 rate-limit-exceeded` |
| Clé d'API | `api_key_id` | ½ du budget projet par défaut | `429 rate-limit-exceeded` |
| Classe de route coûteuse | projet × classe (`payments:write`, `payouts:write`, `notifications:write`) | budgets propres | `429 rate-limit-exceeded` |
| Portail : connexion, codes | IP · identifiant · projet | 5 codes/h/identifiant, 30/h/IP, plafond horaire par projet | `429` (page générique) |
| Console : connexion, MFA | compte · IP | existant (ADR-0046) | `429` |

- Algorithme : seau à jetons dans Valkey, script atomique.
- En-têtes : `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`, et
  `Retry-After` sur `429`.
- Budgets **configurables par projet** (réglage plateforme) ; une
  augmentation est un acte audité.
- **Échec ouvert** si Valkey est indisponible, avec alerte immédiate — la
  protection de débit ne doit pas devenir une panne. Exception : les limites
  de **codes du portail** échouent **fermées** (refus temporaire), car
  l'ouverture permettrait la force brute.

`429 rate-limit-exceeded` (protection de la plateforme) et
`429 quota-exceeded` (limite commerciale, ADR-0074) ont des `type` distincts ;
seul le premier porte `Retry-After`.

### 2. Métriques par projet, cardinalité maîtrisée

`/metrics` expose, avec l'étiquette `project_id` (centaines de valeurs, pas
plus) : requêtes par route et statut, latence (histogrammes), refus de débit,
refus de quota, paiements par statut, notifications par statut, livraisons de
webhook par résultat, profondeur des files, retard du relais de l'outbox,
exceptions d'argent ouvertes. **Jamais** d'identifiant de client, de
référence externe ni de donnée personnelle en étiquette.

### 3. Objectifs de service (SLO), mesurés

| Indicateur | Objectif (mensuel) |
|---|---|
| Disponibilité de l'API `/v1` (réponses non-5xx hors `503` planifiés) | 99,5 % |
| Latence p95 lecture | < 300 ms |
| Latence p95 écriture (hors temps du prestataire) | < 800 ms |
| Délai p95 entre un changement d'état et la première tentative de webhook | < 30 s |
| Retard du relais de l'outbox p99 | < 10 s |
| Contrôles d'invariants du grand livre (ADR-0078 niveau A) | 100 % des exécutions sans violation |

Alertes par **taux de consommation du budget d'erreur** (fenêtres 1 h et 6 h),
pas par seuil brut. Ce sont des **objectifs** publiés et suivis, pas un
engagement contractuel.

### 4. Alertes de sécurité et d'intégrité

Règles dédiées (S-20, ADR-0081) : violation d'invariant, callback rejeté en
rafale, échecs MFA répétés, création de clé, changement de rôle, refus
inter-projets anormaux, somme en `collections` au-delà de 24 h, versement en
résultat ambigu.

### 5. Vue par projet dans la console

La fiche projet (ADR-0080) affiche consommation, refus, erreurs et santé des
webhooks, avec un lien vers le tableau Grafana filtré sur le projet. La
console ne duplique pas Grafana.

## Alternatives écartées

- **Limiter seulement par IP.** Rejeté : une IP sert plusieurs projets et un
  projet tourne derrière plusieurs IP.
- **Compteur de débit transactionnel en base.** Rejeté : coût d'écriture
  disproportionné ; une imprécision de quelques requêtes est sans conséquence.
- **Échec fermé partout.** Rejeté : transformerait une panne de Valkey en
  panne totale.
- **SLA contractuel.** Rejeté pour l'instant : sans historique mesuré ni
  astreinte, un engagement serait une promesse vide.

## Conséquences

- Middleware de débit après `project.auth` (le projet doit être connu pour
  compter).
- Tableaux Grafana par projet et règles d'alerte versionnés dans
  `observability/`.

## Vérification

- Test : un projet au-delà de son budget reçoit `429` avec `Retry-After` ; un
  autre projet n'est pas affecté.
- Test : Valkey coupé → l'API répond, alerte émise ; les codes du portail sont
  refusés.
- Test : aucune étiquette de métrique ne contient de donnée personnelle
  (inspection du registre de métriques).
