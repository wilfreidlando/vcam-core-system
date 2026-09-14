# ADR-0019 — Environnement Docker-first

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : D7

## Contexte

L'hôte de développement de référence n'a pas nativement les extensions requises
par la stack (`redis`, `bcmath`, `intl`, `gmp` manquantes sur les builds PHP
disponibles). Un environnement qui dépend de paquets système installés à la main
dérive entre machines et diverge silencieusement de la CI.

## Décision

- Toute commande de développement passe par le conteneur, via `make` (`make up`,
  `make test`, `make stan`, `make arch`…). L'hôte n'a besoin que de Docker.
- L'image `dev` (Dockerfile, stage `dev`) est l'image que la CI construit et
  utilise pour chaque job (`.github/workflows/ci.yml`) — pas une image proche,
  la même.
- L'utilisateur dans le conteneur est créé avec l'UID/GID de l'hôte
  (`HOST_UID`/`HOST_GID`), pour que les fichiers écrits restent éditables hors
  conteneur.

## Alternatives écartées

- **PHP natif + services Docker pour Postgres/Redis seulement** : boucle de
  feedback plus rapide, mais l'hôte reste un flocon de neige qui peut diverger de
  la CI silencieusement.

## Conséquences

- « Ça marche chez moi mais pas en CI » devient structurellement improbable : les
  deux exécutent la même image.
- Le premier build d'image (~35 s mesurés) est un coût ponctuel, mis en cache
  ensuite (`docker/setup-buildx-action` avec `cache-from: type=gha` en CI).
