# ADR-0010 — Sanctum (opérateurs) et Passport (projets/clients externes)

- **Statut** : Accepté (spécification technique v1.0, ADR-10). Versions remplacées par [ADR-0017](0017-baseline-versions-2026.md) (Passport ^12 → ^13.8).
- **Date** : 2026-09
- **Dérives concernées** : D2, D6

## Contexte

Trois plans d'identité distincts (spécification fonctionnelle §4) : opérateurs
plateforme, applications consommatrices (Project), Parties légères. Les deux
premiers ont des besoins d'authentification différents.

## Décision

- **Plan 1 — opérateurs** : Laravel Sanctum, MFA (TOTP), RBAC.
- **Plan 2 — projets** : `api_keys` (préfixe visible + `key_hash`, jamais le
  secret en clair) pour l'auth serveur-à-serveur, et Laravel Passport (OAuth2,
  scopes) pour les clients externes ayant besoin d'un accès plus fin.
- Séparation stricte des credentials sandbox/live par `environment` sur chaque
  credential.

## Alternatives écartées

- **Un seul mécanisme OAuth2 pour tout** : sur-dimensionné pour l'auth
  serveur-à-serveur interne, qui n'a pas besoin du flux OAuth complet.
- **JWT stateless partout** : complique la révocation immédiate, requise pour un
  système financier.

## Conséquences

- Revérifié le 2026-09-14 : Passport reste la réponse recommandée par
  l'écosystème Laravel en 2026 pour un OAuth2 réel (documenté en 13.x,
  activement publié). Seul le pin de version change.
