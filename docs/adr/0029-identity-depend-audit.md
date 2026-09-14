# ADR-0029 — `identity` peut dépendre de `audit`

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Dérives concernées** : D2, D7

## Contexte

Le Lot 1 démarre : Organizations, Projects, API keys. Sa sortie attendue
(plan de delivery §4, Lot 1) est explicite : « création d'une Organization,
d'un Project, génération de credentials, **appel authentifié tracé et
audité** ». Les opérations administratives sensibles (émission d'une clé
API, révocation) doivent apparaître dans l'audit (spécification fonctionnelle
§10 : « toute opération sensible est explicable et corrélable de bout en
bout »).

Or `tests/Architecture/ModuleGraph.php` n'autorise aujourd'hui **aucun**
module à dépendre de `audit`, hormis `admin`. Une vérification (module
`audit` alors vide) l'a confirmé : rien ne pouvait encore violer la règle,
mais elle bloquait structurellement le besoin réel du Lot 1.

## Décision

`identity` peut dépendre de `audit`, via son `Contracts\AuditLogger`
uniquement (garde-fou G3c inchangé — l'accès reste cantonné au `Contracts/`
de `audit`).

Le module `audit` lui-même reste volontairement mince pour l'instant :
pas de couche `Domain/` séparée de `Contracts/`, la définition d'`AuditEntry`
et de `AuditLogger` vit directement dans `Contracts/`, parce que ce module
n'a aucune règle métier propre — juste un DTO immuable et une interface
d'écriture. Une couche `Domain/` naîtra le jour où une vraie règle
apparaîtra (politique de rétention, redaction spécifique) ; l'ajouter par
anticipation aurait été une indirection sans valeur.

Le mécanisme reste synchrone et appelé directement (pas via l'outbox) :
l'audit d'une action administrative a besoin d'un diff avant/après immédiat,
pas d'un événement de domaine généralisé, et l'outbox n'existe de toute
façon pas encore (Lot 4).

## Alternatives écartées

- **Étendre l'outbox pour publier des entrées d'audit** : l'outbox n'existe
  pas avant le Lot 4 ; le construire maintenant pour ce seul besoin
  inverserait l'ordre du chemin critique du plan de delivery.
- **Autoriser tous les modules capacité à dépendre de `audit` d'un coup**
  (par symétrie avec `events`) : rejeté par prudence — chaque module ajoute
  cette dépendance quand son propre lot en a un besoin réel, pas par
  anticipation. `wallet`, `payment` etc. l'ajouteront au Lot 2/3 s'ils
  auditent des actions administratives (pas les mutations monétaires
  elles-mêmes, qui vivent dans le ledger).

## Conséquences

- `ModuleGraph::ALLOWED['identity']` devient `['support', 'audit']`.
- `deptrac-modules.yaml` (`Identity: [Support, Audit]`) et
  `packages/identity/composer.json` (`require: core/audit`) mis à jour en
  cohérence — `ComposerGraphTest` vérifie les trois artefacts.
