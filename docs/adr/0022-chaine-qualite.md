# ADR-0022 — Chaîne qualité et garde-fous automatisés

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : toutes (D1–D7)

## Contexte

Le document de gouvernance pose la règle directrice : préférer toujours
l'automatique au structurel, et le structurel à l'humain. Les onze garde-fous
qu'il décrit doivent exister avant que du code métier ne s'accumule.

## Décision

Répartition sans doublon, chaque outil couvrant ce qu'il fait le mieux :

| Outil | Rôle | Version pinnée |
| ----- | ---- | -------------- |
| Deptrac | Frontières entre répertoires/namespaces (modules, couches) | `4.7.1` |
| PHPat | Frontières type-aware (héritage, interfaces) — ce que Deptrac ne voit pas | `0.12.4` (0.x, pin exact) |
| PHPStan (règles maison) | Littéraux de chaîne, appels de fonctions globales | `2.2.14` (pin exact) |
| Pest | Ce qui exige une app bootée, un schéma PostgreSQL réel, ou la lecture de métadonnées Composer/YAML | `^5.1` |
| Larastan + strict-rules | Analyse statique niveau 10 (max) | `^3.12`, `^2.0` |
| Infection | Mutation testing, cantonné au domaine financier (nightly) | `^0.35` |
| Pint | Style de code, `declare_strict_types` obligatoire | `^1.32` |
| lefthook | Hooks git locaux, < 10 s | — |
| gitleaks | Scan de secrets | — |

PHPStan niveau 10 dès le premier jour : relever le niveau plus tard n'arrive
jamais en pratique, et l'analyse la plus stricte est la plus utile là où ça
compte — l'arithmétique monétaire et les machines à états.

## Alternatives écartées

- **`composer-unused`** : faux positifs connus sur les service providers
  (façades, bindings) — exactement le profil des 15 providers du projet ; la
  valeur est déjà couverte par `ComposerGraphTest` (G1b).
- **Pact (contract testing)** : coût FFI/Rust + broker pour zéro consommateur
  aujourd'hui. Remplacé par des fixtures Pest (`tests/Contract/`) jusqu'au
  deuxième consommateur réel.
- **`enlightn`** : abandonné, aucun remplaçant crédible identifié.

## Conséquences

- Le registre `docs/architecture/garde-fous-inactifs.md` et
  `tests/Architecture/GuardCoverageTest.php` empêchent qu'une règle « en attente
  de code » soit oubliée ou qu'une règle active soit encore listée comme inactive.
- `tools/guards/verify-guards.sh` (`make guard-test`) injecte une vraie violation
  de chaque garde-fou actif et exige un rejet portant **le message du
  garde-fou** — pas seulement un échec.
