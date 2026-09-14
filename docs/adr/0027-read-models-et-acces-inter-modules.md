# ADR-0027 — Read models et accès inter-modules par `Contracts/`

- **Statut** : Accepté
- **Date** : 2026-09-14
- **Dérives concernées** : D2

## Contexte

La revue d'architecture du 2026-09-14 (§3.2) a signalé qu'`Admin`, dépendant de
tous les modules, risquait de lire leurs tables directement. La vérification a
montré un trou plus large : **n'importe quel** module pouvait le faire.

`deptrac-modules.yaml` autorise Wallet à dépendre de Ledger (niveau module).
`deptrac-layers.yaml` autorise Application à dépendre de Domain (niveau
couche). Chaque configuration Deptrac ne voit qu'une seule dimension à la fois
et ne peut pas distinguer « mon propre Domain » du « Domain d'un autre module » —
si bien que `Core\Wallet\Application` utilisant `Core\Ledger\Domain` satisfaisait
les deux règles simultanément. Une sonde délibérée (classe de `Wallet`
dépendant directement d'une classe de `Ledger\Infrastructure`) a confirmé
zéro violation avant correction.

## Décision

- **Propriété des read models** : chaque module publie ses propres modèles de
  lecture dans son propre `Core\<Module>\Contracts`, sous forme de DTO immuables
  sans dépendance framework (déjà couvert par G3b).
- **Aucune jointure SQL entre contextes.** La corrélation entre données de
  plusieurs modules se fait par identifiant (`external_ref`, `correlation_id`),
  jamais par jointure de base.
- **`Admin` compose, il n'interroge pas directement.** Il agrège plusieurs
  appels aux `Contracts/` de chaque module ; jamais d'Eloquent cross-contexte.
- **Nouveau garde-fou G3c**
  (`tools/PhpStan/Architecture/ModulesMeetOnlyThroughContracts.php`) : une
  règle PHPat par module, qui interdit à ce module de référencer une classe
  d'un autre module en dehors de son `Contracts\`. `ComposerGraphTest` échoue
  si un module du graphe n'a pas sa règle dédiée — impossible d'ajouter un
  module sans être couvert.
- Le commentaire de tête de `deptrac-layers.yaml` est corrigé : il affirmait à
  tort que l'accès inter-modules passait nécessairement par `Contracts/`.

## Alternatives écartées

- **Un module `Reporting` dédié qui agrège tout** : ajouterait un module de plus
  avec son propre accès à toutes les données, déplaçant le problème plutôt que
  le résolvant.
- **Compter sur la revue de code seule** : c'est la ligne de défense la plus
  faible (gouvernance §2) ; la sonde a montré qu'elle avait déjà échoué une fois
  silencieusement.

## Conséquences

- Toute PR qui fait dépendre un module d'un autre en dehors de `Contracts/`
  échoue immédiatement en CI, avec le message de G3c nommant les deux modules.
- `make guard-test` inclut une sonde G3c : Wallet référençant directement une
  classe `Domain` de Ledger doit être rejeté avec ce message précis.
