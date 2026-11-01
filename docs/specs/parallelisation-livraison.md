# Paralléliser la livraison — le protocole par secteur

Le plan (`docs/specs/plan-delivery-mvp.md`) organise le **travail**. Ce
document organise son **exécution** : plusieurs agents, en parallèle, sur des
parties qui ne se marchent pas dessus — sans que chacun rejoue toute la
suite de tests.

## Le problème

La suite complète prend **99 secondes** (1 390 tests) et la seule suite
d'architecture en prend **82** : les 167 cas IDOR générés, un par route
console. Multiplier cela par huit sessions en parallèle donne huit fois le
même travail — et, pire, huit sessions qui se disputent **la même base
`core_testing`**.

La réponse n'est pas « des tests plus rapides » mais « **des tests
sectorisés** » : un agent ne rejoue que ce que son changement peut casser.

## Les secteurs

Un secteur = un contexte borné = un module. La suite du secteur est déclarée
dans `phpunit.xml` (une `<testsuite>` par module) et la cartographie des
routes console vers leur secteur vit dans
`tests/Architecture/Idor/IdorSector.php`.

| Secteur | Ce qu'il possède | Suite |
| ------- | ----------------- | ----- |
| `billing` | clients, catalogue, abonnements, factures, encaissement | `packages/billing/tests` |
| `payment` | paiements, prestataires, callbacks, refunds | `packages/payment/tests` |
| `identity` | organisations, projets, clés, opérateurs, MFA | `packages/identity/tests` |
| `ledger` | grand livre, invariants, exceptions d'argent | `packages/ledger/tests` |
| `wallet` | portefeuilles, transferts, sorties | `packages/wallet/tests` |
| `parties` | tiers sans identité | `packages/parties/tests` |
| `notification` | notifications, cascade de secours, WAHA | `packages/notification/tests` |
| `webhook` | endpoints, livraisons, DLQ, rejeu | `packages/webhook/tests` |
| `events` | outbox transactionnelle, relais | `packages/events/tests` |
| `audit` | journal infalsifiable, lecture scopée | `packages/audit/tests` |
| `admin` | console opérateur | `packages/admin/tests` |
| `support` | socle partagé | `packages/support/tests` |
| `fees` · `storage` · `developer-platform` | scaffolds | leurs suites |

## Les commandes

```bash
make sectors                                        # la liste et ce que chacun possède

make test-sector  SECTOR=billing DB=core_testing_s1   # la suite du secteur
make check-sector SECTOR=billing DB=core_testing_s1   # le gate complet du secteur
```

`check-sector` enchaîne : Pint, PHPStan niveau 10, la suite du secteur, la
suite **Schema** (4 s — elle vérifie les colonnes d'argent et de donnée
personnelle, que n'importe quelle migration peut casser) et les **cas IDOR
du secteur** (le filtre `billing::` sur les cas générés).

### Ce que ça coûte

| | Avant | Après (secteur `billing`) |
| - | ----- | ----------------------- |
| Suite de tests | 99 s | **5 s** |
| Cas IDOR | 82 s | **26 s** (58 cas) |
| Style + analyse statique | 3 s | 3 s |
| Schema | 4 s | 4 s |
| **Total** | **188 s** | **38 s** |

## Les règles de la parallélisation

1. **Une branche par session.** `git checkout -b lot-13 develop`. Personne ne
   commite sur `develop` pendant qu'une autre session travaille.
2. **Une base par session.** `DB=core_testing_<suffixe>`. La suite de test
   partage une base : deux sessions sur la même base se marchent dessus, et
   un `migrate:fresh` manuel dessus casse le travail des autres.
3. **Jamais de `migrate:fresh`, `migrate:reset` ou `migrate:rollback` à la
   main.** Ni sur `core_testing`, ni sur une base de session : on passe par
   les cibles `make`.
4. **Le secteur ne suffit pas à prouver qu'on n'a rien cassé ailleurs.** Le
   gate d'intégration (`make ci`) existe pour cela : une session, à la fin,
   rejoue **tout** — suite complète, `guard-test`, OpenAPI, deptrac, PHPStan.
5. **Ce qui traverse les secteurs ne se fait pas en parallèle.** Une arête
   du graphe de modules, un `Contracts/` partagé, une migration : c'est un
   point de contention. Le plan dit lesquels (§P4).

## Ce qui n'est pas sectorisable — et pourquoi

| Suite | Durée | Pourquoi |
| ----- | ----- | -------- |
| `Architecture` (hors IDOR) | 3 s | déjà rapide, et **global** par nature : le graphe de modules, les statuts canoniques, la fuite de secrets — c'est la règle commune |
| `Invariants` | 3 s | les invariants financiers sont **transverses** : ils portent sur l'ensemble, pas sur un module |
| `Contract` | 1 s | le contrat consommateur est transversal par définition |
| `Feature` (console web) | 40 s | les parcours console traversent identité, audit et facturation : ils ne sont pas sectorisables sans les trahir |
| `App` (`tests/Unit`, `tests/Integration`) | ~40 s | idem : les exemples documentés de l'API traversent paiement, portefeuille, notification et identité |

Ces suites-là sont **le gate d'intégration**, pas le gate d'un agent.

## Le gate d'intégration

Une session, à la fin de la vague — ou la CI :

```bash
make ci          # hygiene, lint-check, stan, arch, schema, test
make guard-test  # chaque garde-fou doit refuser sa violation
make openapi     # les deux snapshots, sans rupture
```

C'est là que la suite complète a son sens : elle prouve que les secteurs,
testés séparément, **se tiennent ensemble**. C'est le seul moment où
l'absence de conflit se vérifie.

## Le garde-fou

`IdorGuardTest` refuse qu'une route console échappe à un secteur : une route
que personne ne revendique cesserait d'être testée par personne, en silence.
Le secteur est donc un fait vérifié, pas une intention.
