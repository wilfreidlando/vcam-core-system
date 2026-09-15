# ADR-0032 — L'écriture de l'outbox est avancée, en parallèle du Lot 2

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Dérives concernées** : D1, D6

## Contexte

Le Lot 2 (Money Core) démarre. Toute mutation de wallet doit, dans la même
transaction que l'écriture ledger, publier un événement
(`wallet.credited`/`wallet.debited` — spécification technique §12.2). Le
garde-fou **G8** interdit tout `event()`/`dispatch()` en dehors de l'outbox —
mais l'outbox elle-même (table `event_outbox` + relayeur) est nominalement
prévue au Lot 4.

Sans solution, deux mauvaises options se présentent : soit Wallet n'émet
aucun événement (le catalogue d'événements de la spec resterait creux pour la
plus critique des capacités), soit Wallet contourne G8. Aucune des deux n'est
acceptable.

Le plan de delivery anticipe déjà ce chevauchement : sa table de dépendances
(§5) indique explicitement « Lot 2 peut démarrer en parallèle de : Lot 4
(partie infra outbox) ».

## Décision

Seule la **moitié écriture** de l'outbox est construite maintenant, dans le
module `events` : la table `event_outbox` (DDL spec technique §6.8) et un
port `EventOutbox::append()` implémenté en Eloquent, appelé dans la même
transaction que toute mutation ledger/wallet.

La **moitié lecture/relais** (job Horizon qui lit `published_at IS NULL`,
dispatche vers les listeners internes et vers le module Webhook) reste au
Lot 4, sans changement : elle a une dépendance réelle vers Horizon et vers
`webhook`, qui n'existe pas encore.

Conséquence assumée et délibérée : les événements sont bien écrits,
atomiquement, dès le Lot 2, mais **personne ne les consomme avant le Lot 4**.
C'est correct — l'outbox garantit la cohérence état/événement dès
l'écriture ; le relais est un problème de livraison, orthogonal.

## Alternatives écartées

- **Wallet n'émet aucun événement au Lot 2** : laisse le catalogue
  d'événements creux et impose de retoucher le code de mutation de wallet —
  le code le plus sensible du projet — une fois le Lot 4 arrivé. Le risque
  de régression sur du code financier déjà testé et en production est pire
  que le coût de construire la table maintenant.
- **Table `event_outbox` complète avec relais simplifié maintenant** : le
  relais a une dépendance réelle vers Horizon/Webhook ; le construire
  prématurément reviendrait à faire le Lot 4 en avance sans les capacités
  qui le justifient (personne n'a encore de webhook à livrer).

## Conséquences

- `packages/events` accueille sa première implémentation réelle : migration
  `event_outbox`, `Contracts\DomainEvent` (VO), `Contracts\EventOutbox`
  (port), implémentation Eloquent.
- Le Lot 4 n'aura qu'à écrire le relayeur (job Horizon) contre une table déjà
  peuplée et déjà testée — pas à concevoir le schéma sous pression.
- `docs/architecture/garde-fous-inactifs.md` : **G8c** (l'écriture outbox
  partage la transaction de l'état) devient testable dès le Lot 2, avec de
  vraies mutations de wallet comme matière.
