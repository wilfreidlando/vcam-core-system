# ADR-0001 — Monolithe modulaire Laravel

- **Statut** : Accepté (spécification technique v1.0, ADR-01). Versions de framework remplacées par [ADR-0017](0017-baseline-versions-2026.md).
- **Date** : 2026-09
- **Dérives concernées** : D1, D2

## Contexte

Volumétrie visée la première année : moins de 10 produits consommateurs,
quelques milliers de transactions par jour. Équipe de 3 à 4 développeurs backend.
Les capacités doivent pouvoir être extraites en services indépendants plus tard,
sans réécriture.

## Décision

Une seule application Laravel déployable, découpée en contextes bornés
faiblement couplés ([ADR-0002](0002-contextes-bornes-packages-composer.md)), qui
communiquent par interfaces publiées et par événements
([ADR-0008](0008-outbox-transactionnel.md)).

## Alternatives écartées

- **Microservices dès le départ** : déploiement, observabilité distribuée et
  cohérence transactionnelle entre services ont un coût disproportionné pour
  cette volumétrie et cette équipe.
- **Monolithe non structuré** : rapide au début, impossible à extraire ensuite ;
  c'est la dérive D1/D2 par construction.

## Conséquences

- Une transaction PostgreSQL peut couvrir plusieurs modules. C'est une facilité
  à utiliser avec discipline : les modules restent en contact par leurs contrats.
- La promesse d'extraction n'est tenue que si les frontières sont vérifiées
  automatiquement ([ADR-0016](0016-internals-hexagonaux.md),
  [ADR-0027](0027-read-models-et-acces-inter-modules.md)).
- Une extraction se justifie par la mesure (charge, cadence de déploiement,
  exigence contractuelle d'un client), jamais par principe.
