# ADR-0048 — billing, storage, fees, developer-platform hors périmètre de la version de production

- **Statut** : Accepté
- **Date** : 2026-09-19
- **Décideurs** : décision de l'utilisateur, précisée par investigation factuelle
- **Dérives concernées** : D1 périmètre · D7 organisationnelle

## Contexte

L'utilisateur a demandé une version de production de ce Core sans les
modules `billing`, `storage`, `fees` et `developer-platform`. Une première
proposition envisageait un retrait structurel (suppression des packages,
édition de `ModuleGraph.php`/`deptrac-modules.yaml`/`composer.json`) —
explicitement rejetée pendant la revue du plan : **« pour les modules que je
ne veux pas, on ne retire pas, on les laisse juste vides. »**

Un état des lieux factuel confirme que les 4 modules sont des scaffolds
Lot 0 authentiquement vides : aucune migration, aucun modèle, aucun use
case, aucune route, aucun test — seulement un `ServiceProvider` dont
`register()`/`boot()` ne font rien d'observable, et des README génériques.
Recherche exhaustive (`grep` sur tout le dépôt hors de leur propre
répertoire) : **zéro référence** à `Core\Billing`, `Core\Storage`,
`Core\Fees` ou `Core\DeveloperPlatform` nulle part ailleurs dans le code, y
compris dans `admin` qui a pourtant la permission d'en dépendre dans le
graphe. Dans cet état, les 4 modules n'ont **aucun effet observable** sur
une installation — les laisser en place ne charge aucune route, aucune
migration, aucun binding.

Nuance sur `fees` : contrairement aux trois autres (déjà hors-MVP dans la
spec technique elle-même), `fees` est en scope MVP (Lot 3) et était en pause
depuis ADR-0044 avec la note explicite « l'utilisateur n'a pas tranché sa
pertinence ». Ce message tranche : `fees` sort du périmètre de production
au même titre que les trois autres.

`storage` a une empreinte infrastructure non utilisée : un service `minio`
dans `compose.yaml`, gated derrière un profil Docker Compose `storage` (off
par défaut), et les variables S3/MinIO correspondantes dans `.env.example`.

## Décision

**Aucun retrait structurel.** `ModuleGraph.php`, `deptrac-modules.yaml`, la
règle PHPat `ModulesMeetOnlyThroughContracts`, `composer.json` (racine et
`packages/admin`), `compose.yaml`, `.env.example` restent tels quels. Les 4
scaffolds restent dans le dépôt exactement comme avant cet ADR — présents
pour honorer le découpage complet de la spec technique §4 (ADR-0002) et
rester réactivables sans reconstruction si un besoin réel émerge.

Ce qui change : une décision explicite, tracée, que ces 4 modules ne font
pas partie du périmètre de la **version de production** de ce Core.
Documentation mise à jour en conséquence (`CLAUDE.md`,
`docs/architecture/vue-ensemble.md`) pour refléter « hors périmètre
production », distinct de « scaffold, reste à construire » (qui laissait
entendre un simple retard de planning plutôt qu'une décision de scope).

## Alternatives écartées

- **Retrait structurel complet** (suppression des packages + édition du
  graphe de modules) — rejeté explicitement par l'utilisateur. Aurait été
  sûr techniquement (`ComposerGraphTest` aurait forcé la synchronisation
  des trois sources de vérité), mais ce n'est pas la décision demandée :
  les scaffolds ont une valeur en soi (cohérence avec la spec, coût de
  réintroduction nul) même sans code métier derrière.
- **Retrait du profil `minio`/variables storage de l'infra** — écarté pour
  cohérence avec la décision « on ne retire rien » : le profil reste
  disponible, optionnel, off par défaut, comme avant.

## Conséquences

- Aucun changement de comportement runtime : les 4 modules n'avaient et
  n'ont toujours aucun effet observable.
- Réintroduire l'un de ces modules dans le périmètre de production repasse
  par les quatre portes de gouvernance (transverse → générique → gain >
  coût → contractualisable, `docs/specs/gouvernance-anti-derive.md` §4) —
  la présence du scaffold ne présume d'aucune décision future, dans un sens
  ou dans l'autre.
- `fees` n'est plus « en pause, non tranché » (ADR-0044) mais « hors
  périmètre, tranché » — si le besoin réapparaît, un nouvel ADR le
  réintroduirait explicitement plutôt que de lever une pause implicite.
