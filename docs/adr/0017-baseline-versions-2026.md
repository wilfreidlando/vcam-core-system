# ADR-0017 — Baseline Laravel 13 / PHP 8.4 / PostgreSQL 18 / Valkey 8.1

- **Statut** : Accepté — écart assumé par rapport à la spécification technique v1.0
- **Date** : 2026-09-14
- **Dérives concernées** : D6, D7

## Contexte

La spécification technique v1.0 (rédigée septembre 2026) pin Laravel 11, PHP
8.3, Laravel Sanctum ^4, Laravel Passport ^12, brick/money ^0.9, PostgreSQL 16,
Redis 7. Vérification à la date de rédaction de cette Phase 0 (2026-09-14,
sources : laravel.com/docs, php.net/supported-versions, endoflife.date) :

| Composant | Pin de la spec | État réel au 2026-09-14 |
| --------- | --------------- | ------------------------ |
| Laravel 11 | requis | Support sécurité **terminé le 2026-03-12** |
| PHP 8.3 | requis | Sorti du support actif le **2025-12-31** (sécurité seule) |
| Laravel 13 | — | Version courante, corrections de sécurité jusqu'au 2028-03 |
| PHP 8.4 | — | Support actif jusqu'au 2026-12-31, sécurité jusqu'au 2028-12-31 |
| Redis | 7 | Redis 8.x est passé en licence AGPLv3/RSALv2/SSPLv1 (copyleft) |

Suivre la spécification à la lettre reviendrait à démarrer un projet neuf sur
une branche déjà en fin de vie, nécessitant deux montées de version majeures
immédiatement.

## Décision

- **Laravel 13**, **PHP 8.4** (le plancher de Laravel 13 est PHP 8.3, déjà en
  sécurité seule — 8.4 est le choix défendable le plus bas).
- **Laravel Passport ^13.8**, **Laravel Sanctum ^4.3**, **Laravel Horizon
  ^5.49** (absent du périmètre initial malgré ADR-11 ; ajouté), **brick/money
  ^0.15**.
- **PostgreSQL 18** (`--data-checksums` activé) plutôt que 16 : la version 16
  ne laissait que ~2 ans de marge pour un système pensé pour durer.
- **Valkey 8.1** plutôt que Redis 8.x : protocole compatible, fonctionne sans
  changement avec Horizon et `phpredis`, licence BSD-3 sans ambiguïté pour une
  plateforme financière propriétaire.

## Alternatives écartées

- **Suivre la spec à la lettre (Laravel 11 / PHP 8.3)** : démarrer sur une
  branche sans correctifs de sécurité est indéfendable pour un système
  financier, quel que soit le texte de la spécification.
- **PHP 8.5** : support actif jusqu'en 2027, sécurité jusqu'en 2029 — marge
  supplémentaire réelle, mais non installé nativement sur l'hôte de
  développement (seuls 8.1/8.2/8.4 présents) et écosystème plus jeune. Écarté au
  profit de 8.4, qui reste défendable et mieux couvert par l'écosystème
  aujourd'hui ; réévaluable sans coût grâce à Docker ([ADR-0019](0019-docker-first.md)).

## Conséquences

- ADR-10 (Sanctum/Passport, [ADR-0010](0010-sanctum-et-passport.md)),
  ADR-03 (PostgreSQL, [ADR-0003](0003-postgresql.md)) et ADR-11
  (files/cache, [ADR-0011](0011-files-cache-verrous-valkey-horizon.md)) restent
  valides sur le principe ; seuls les numéros de version changent.
- `brick/money` reste en 0.x (jamais sorti de version 1.0) : minors potentiellement
  cassantes. D'où l'enveloppe `Core\Support\Money` ([ADR-0021](0021-wrapper-money.md)),
  qui isole ce risque à un seul fichier.
- Revue de cette baseline à prévoir avant la fin du support sécurité de Laravel
  13 (2028-03) ou de PHP 8.4 (2028-12), selon l'échéance la plus proche.
