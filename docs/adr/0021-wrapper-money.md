# ADR-0021 — Enveloppe `Money` au-dessus de brick/money

- **Statut** : Accepté — design ; implémentation au Lot 2
- **Date** : 2026-09-14
- **Dérives concernées** : D6, D7 (dette technique de dépendance)

## Contexte

`brick/money` est la bibliothèque de calcul retenue (spécification technique
§3, §7) : elle empêche les opérations entre devises et gère l'arrondi
explicitement. Elle n'a cependant jamais atteint la version 1.0 — encore en
`0.15` au 2026-09-14 ([ADR-0017](0017-baseline-versions-2026.md)) — et ses
versions mineures peuvent introduire des ruptures.

## Décision

`Brick\Money\Money` ne traverse jamais une frontière de module. Une enveloppe
`Core\Support\Money` (value object immuable, construite à partir d'un montant
en minor units + un code devise) est le seul type monétaire que les
`Contracts/` des modules exposent. La conversion vers/depuis `Brick\Money` reste
interne à cette enveloppe. Garde-fou : règle PHPat
`test_money_library_is_wrapped_by_the_shared_kernel`
(`tools/PhpStan/Architecture/PersistenceStaysInInfrastructure.php`), qui
interdit toute référence à `Brick\*` en dehors de `Core\Support\Money`.

## Alternatives écartées

- **Utiliser `Brick\Money\Money` directement partout** : une montée de version
  cassante de brick/money forcerait alors une modification dans chaque module,
  potentiellement jusque dans des `Contracts/` publiés.

## Conséquences

- Une future montée de version cassante de brick/money (ou même son
  remplacement) reste un changement d'un seul fichier.
- Implémentation au Lot 2, premier module à manipuler des montants au-delà de
  la simple validation de schéma.
