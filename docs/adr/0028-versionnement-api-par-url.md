# ADR-0028 — Versionnement d'API par préfixe d'URL

- **Statut** : Accepté — fenêtre de dépréciation à confirmer avant Lot 2
- **Date** : 2026-09-14
- **Dérives concernées** : D5

## Contexte

La spécification technique §13 prévoit déjà un versionnement par URL
(`/v1/...`). La revue d'architecture (§3.7) posait la question sans trancher
entre préfixe d'URL, en-tête `Accept` versionné, ou sous-domaine.

## Décision

- Préfixe d'URL : `/v1/...`, `/v2/...`. `bootstrap/app.php` fixe `apiPrefix: ''`
  pour que chaque module maîtrise son propre préfixe versionné dans ses routes
  ([ADR-0018](0018-routes-possedees-par-les-modules.md)), plutôt que le `/api`
  par défaut de Laravel.
- Un changement incompatible = une nouvelle version, jamais une modification
  silencieuse de la version existante.
- Garde-fou **G9** : `oasdiff breaking` en CI contre le contrat OpenAPI
  (généré par Scramble) du commit de base ; toute rupture sans montée de
  version fait échouer la CI. Actif dès le premier endpoint publié (Lot 1).

## Pourquoi le préfixe d'URL plutôt que l'en-tête `Accept`

- Le plus simple à router avec les mécanismes natifs de Laravel.
- Visible directement dans les logs, les traces et la documentation, sans
  décoder un en-tête.
- Fonctionne pour tout type de consommateur (navigateur, SDK, script), alors
  qu'un en-tête custom suppose un client qui sait le poser.

## Alternatives écartées

- **En-tête `Accept: application/vnd.core.v2+json`** : plus « propre »
  RESTfully, mais invisible dans un log d'URL et plus difficile à tester
  manuellement (curl, navigateur).
- **Sous-domaine `v2.api...`** : complique le certificat TLS et le routing pour
  un bénéfice non démontré à cette échelle.

## Conséquences

- **À trancher avant le Lot 2** : la fenêtre de dépréciation exacte (durée
  pendant laquelle une version dépréciée reste servie) — dépend du nombre de
  consommateurs externes à ce moment-là, encore nul aujourd'hui.
