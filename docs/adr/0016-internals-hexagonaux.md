# ADR-0016 — Internals hexagonaux par module (écart assumé vs l'arborescence illustrative)

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : D1, D2

## Contexte

Le plan de delivery (§7.2) illustre une arborescence plate par module
(`Models/`, `Http/`, `Services/`, `Contracts/`, `Events/`). C'est un exemple, pas
une prescription — mais suivi tel quel, il ne distingue pas ce qui est framework
de ce qui est métier, et rend l'extraction future d'un module coûteuse (promesse
de l'[ADR-0001](0001-monolithe-modulaire.md)).

## Décision

Chaque module est structuré en couches, dépendances vers l'intérieur uniquement :

```
Presentation ─┐
Infrastructure ┼─► Application ─► Domain
              ─┘
```

- `Domain/` : PHP pur (entités, value objects, événements de domaine,
  exceptions, ports). Aucune dépendance framework — garde-fou **G3b**.
- `Application/` : cas d'usage et DTO ; orchestration transactionnelle.
- `Infrastructure/` : implémentations des ports (Eloquent, HTTP, providers).
- `Presentation/` : contrôleurs, requests, resources, middleware, commandes.
- `Contracts/` : la **seule** surface qu'un autre module peut consommer —
  garde-fous **G3** (Deptrac, couches) et **G3c** (PHPat, inter-modules réels).

## Alternatives écartées

- **Suivre l'arborescence plate du plan de delivery** : plus rapide à écrire au
  premier jour, mais mélange logique métier et framework dès la première classe ;
  rend impossible de vérifier automatiquement la pureté du domaine.

## Conséquences

- Toute classe de `Domain/` ou `Contracts/` qui référence Laravel/Eloquent/Symfony
  échoue la CI (G3b).
- Un « premier jour » légèrement plus lent contre une extraction future réellement
  possible.
