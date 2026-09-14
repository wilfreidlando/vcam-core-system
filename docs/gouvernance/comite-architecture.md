# Comité d'architecture

Source : `docs/specs/gouvernance-anti-derive.md` §11 et
`docs/specs/plan-delivery-mvp.md` §3.2.

## Composition

- Tech Lead / Architecte plateforme.
- Un pair technique.
- Un représentant produit, tournant selon le sujet traité.

## Droits de décision (ce que le comité, et lui seul, tranche)

- L'entrée d'une capacité ou d'un domaine dans le Core (via les quatre portes
  — skill `porte-entree-capacite`).
- Toute évolution d'un contrat d'API public.
- L'octroi d'une exception temporaire, toujours datée
  (`docs/gouvernance/registre-exceptions.md`).
- L'arbitrage des conflits de priorité entre équipes consommatrices.
- Toute modification du graphe de dépendances entre modules
  (`tests/Architecture/ModuleGraph.php`).

## RACI — entrée d'un domaine dans le Core

| Acteur | Rôle |
| ------ | ---- |
| Équipe demandeuse | Responsable de la demande et du dossier |
| Comité d'architecture | Décideur (accountable) |
| Propriétaire plateforme | Consulté |
| Équipes consommatrices | Informées |

## Cadence

Rituel court toutes les deux semaines, plus des saisines à la demande pour les
décisions bloquantes. Une revue de risque est tenue à M2 (fin du Lot 2 /
Money Core) et à M7 (Lot 7 / migration pilote) —
`docs/specs/plan-delivery-mvp.md` §6.

## SLA interne envers les équipes produit

L'équipe plateforme s'engage sur un délai de traitement des demandes des
équipes consommatrices : réponse sous 2 jours, évolution mineure de contrat
sous un sprint. Sans ce SLA, les produits contournent le Core et
l'investissement est perdu (`docs/specs/plan-delivery-mvp.md` §3.3).

## Revue périodique

Une revue d'architecture trimestrielle examine les indicateurs de dérive
(`docs/gouvernance/indicateurs-derive.md`), la liste négative
(`docs/gouvernance/liste-negative.md`), et les exceptions arrivant à échéance
(`docs/gouvernance/registre-exceptions.md`).
