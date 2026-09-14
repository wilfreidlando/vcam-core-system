# ADR-0018 — Routes enregistrées par chaque module

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : D1, D2, D7

## Contexte

Le plan de delivery (§7.2) montre `routes/api.php` agrégeant les routes de tous
les modules. Un fichier central que chaque équipe édite pour publier sa propre
capacité est un point de contention et une source de conflits de merge, à
l'opposé du principe « une capacité est construite une fois, par une équipe ».

## Décision

- `routes/api.php` ne contient que les endpoints transverses de plateforme (santé
  de disponibilité `/health/ready`, et rien de spécifique à une capacité).
- Chaque `<Module>ServiceProvider::boot()` charge ses propres routes depuis
  `packages/<module>/routes/`.

## Alternatives écartées

- **Fichier central agrégé** (tel qu'illustré dans le plan de delivery) : écarté
  pour la raison ci-dessus. Le plan décrit une arborescence illustrative, pas une
  exigence contractuelle.

## Conséquences

- Ajouter un endpoint à une capacité ne touche jamais un fichier partagé.
- Le préfixe `/v1` des routes est celui du module ([ADR-0028](0028-versionnement-api-par-url.md)),
  pas celui du fichier racine.
