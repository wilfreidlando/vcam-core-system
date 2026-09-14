# ADR-0030 — Chaque namespace de tests d'un module doit être déclaré dans `autoload-dev` du `composer.json` racine

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Dérives concernées** : D7 (piège récurrent pour l'équipe)

## Contexte

`packages/identity/composer.json` déclare
`"autoload-dev": {"psr-4": {"Core\\Identity\\Tests\\": "tests/"}}`, exactement
comme le `composer.json` racine déclare son propre `autoload-dev`. Les tests
Domain/Application du Lot 1 utilisent des doublures de test (fakes) sous
`Core\Identity\Tests\Unit\Fakes\*`.

À l'exécution, `vendor/bin/pest` échouait avec `Class
"Core\Identity\Tests\Unit\Fakes\InMemoryApiKeyRepository" not found` bien que
le fichier existe et que la classe soit correctement déclarée. Cause : **Composer
ne fusionne jamais la section `autoload-dev` d'un package requis (y compris un
path repository) dans l'autoloader du projet — seule celle du package racine
est chargée**, quel que soit l'environnement (`--dev` ou non). C'est documenté
mais facile à oublier dans un monorepo à path repositories, où chaque module
« ressemble » à un projet autonome.

Les classes `Core\Identity\Domain\*` fonctionnaient, elles, sans problème :
elles sont dans la section `autoload` (production) d'`identity`, chargée
normalement pour toute dépendance.

## Décision

Le `composer.json` **racine** déclare, dans son propre `autoload-dev.psr-4`,
une entrée pour le namespace de tests de **chaque module qui a des classes de
support de test** (fakes, builders, traits partagés) — pas pour les simples
fichiers de test Pest (`it(...)`, `test(...)`), qui n'ont pas besoin d'être
autoloadés en tant que classes :

```json
"autoload-dev": {
    "psr-4": {
        "Core\\Identity\\Tests\\": "packages/identity/tests/"
    }
}
```

Le `autoload-dev` de chaque `packages/<module>/composer.json` reste néanmoins
déclaré (documentation, cohérence avec le reste du module) mais est inerte tel
quel côté runtime du projet complet.

## Alternatives écartées

- **Déplacer les fakes dans `autoload` (production) du module** : les
  doublures de test se retrouveraient packagées avec le code de production,
  potentiellement chargées en production — inacceptable.
- **Un `composer.json` par module avec ses propres `vendor/` et exécution
  séparée des tests** : perdrait l'intérêt du monorepo (une seule commande,
  un seul autoloader, tests inter-modules simples).

## Conséquences

- Toute PR qui ajoute des classes de support de test (pas de simples
  `it()`/`test()` Pest) à un module doit ajouter l'entrée correspondante ici.
- `composer dump-autoload` doit être relancé après l'ajout.
- La skill `nouveau-contexte-borne` devrait rappeler ce point si le nouveau
  module a des fakes dès sa création — à vérifier lors de sa prochaine
  utilisation.
