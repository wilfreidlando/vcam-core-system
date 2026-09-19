# ADR-0049 — Documentation API séparée pour le plan opérateur/admin

- **Statut** : Accepté
- **Date** : 2026-09-19
- **Décideurs** : décision de l'utilisateur
- **Dérives concernées** : D5 contrat

## Contexte

Un seul document OpenAPI (`openapi/v1.json`, `/docs/api`) couvrait à la fois
le plan d'intégration Project/API-key (`GET /v1/whoami`, `POST /v1/payments`,
`POST /v1/notifications/*`…) et le plan opérateur/admin
(`/v1/admin/*`, `/v1/operators/*`). L'utilisateur a noté que ça crée de la
confusion : les endpoints admin ne relèvent pas d'une intégration tierce mais
de l'usage de l'application elle-même par ses propres opérateurs — un
intégrateur Project qui consulte `/docs/api` n'a aucune raison de voir
`POST /v1/admin/payment-reconciliation-runs` à côté de `POST /v1/payments`.

Scramble (`Dedoc\Scramble\Scramble::registerApi()`) permet nativement
plusieurs documents OpenAPI indépendants dans une même application, chacun
avec son propre filtre de routes, son propre schéma de sécurité, sa propre
route d'exposition.

## Décision

Deux documents distincts :

- **« Core System API »** (inchangé dans son rôle) — `api_path` limité à
  `v1` en excluant explicitement `v1/admin` et `v1/operators`
  (`config/scramble.php`). Exposé sur `/docs/api` (`openapi/v1.json`),
  schéma de sécurité `pk_<env>_<id>.<secret>` (`project.auth`) inchangé.
- **« Core Admin API »** (nouveau) — tout `v1/admin/*` **et** `v1/operators/*`
  (le plan opérateur au complet, y compris login/MFA, qui n'a de sens que
  pour la console, jamais pour un intégrateur tiers). Exposé sur
  `/docs/admin` (`openapi/admin.json`), schéma de sécurité bearer générique
  (`operator.auth`) — pas le format de clé Project, qui induirait en erreur.
  `info.description` indique explicitement qu'il ne s'agit pas d'une
  surface d'intégration tierce.

Implémentation : `AppServiceProvider::configureAdminApiDocumentation()`
enregistre la seconde API — `Scramble::registerApi('admin', [...])` clone la
configuration par défaut (même renderer, mêmes dev-tools) puis ne
surcharge que `api_path`, `info`, `ui.title` et `security_strategy`.
`make openapi` régénère les deux snapshots ; le lint Spectral (CI) tourne
sur les deux fichiers.

## Alternatives écartées

- **Un seul document avec des tags séparés** — rejeté : un tag ne change ni
  le schéma de sécurité affiché ni la liste des serveurs, et un intégrateur
  verrait quand même les endpoints admin en parcourant le document complet.
- **Restreindre l'accès à `/docs/admin` par IP/réseau** — hors périmètre de
  cet ADR ; `RestrictedDocsAccess` (déjà appliqué aux deux documents via la
  config Scramble héritée) couvre le contrôle d'accès existant, inchangé.

## Conséquences

- `openapi/admin.json` est un nouveau fichier suivi par git, régénéré par
  `make openapi` au même titre que `openapi/v1.json`.
- Le garde-fou G9 (changement cassant non versionné) reste scopé à
  `openapi/v1.json` — la surface d'intégration tierce. Étendre la même
  garantie à `openapi/admin.json` reste une extension possible, non traitée
  ici : le plan opérateur n'a pas le même besoin de stabilité contractuelle
  externe qu'une API tierce.
- Vérifié : `GET /docs/api` et `GET /docs/admin` répondent 200 ; `api.json`
  ne contient aucun chemin `/admin`/`/operators` (zéro fuite, confirmé) ;
  `admin.json` contient exactement les 8 endpoints admin + 6 endpoints
  opérateur ; lint Spectral clean sur les deux documents.
