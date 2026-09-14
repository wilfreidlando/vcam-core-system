---
name: nouveau-cas-usage
description: Scaffold a new use case (Application layer) inside an existing bounded context, with its Domain port dependencies and outbox-based event emission wired correctly from the start. Use when asked to add a new operation to a module (e.g. "add a CreditWallet use case").
allowed-tools: Read, Write, Edit
---

# Nouveau cas d'usage

Un cas d'usage est une classe, un comportement : la transaction s'ouvre, les
règles de domaine s'appliquent, l'état est persisté, l'événement est écrit
dans l'outbox, la transaction se ferme.

## Étapes

1. **Situer le module** : `packages/<module>/src/Application/UseCase/`.

2. **Écrire le DTO d'entrée** dans `Application/Dto/`, immuable
   (`final readonly class`), un seul objet par cas d'usage.

3. **Écrire le cas d'usage** :
   - Une classe, une méthode publique (`__invoke` ou un verbe explicite).
   - Ne dépend que de `Domain/Port/*` (interfaces) injectées par le
     constructeur — jamais d'Eloquent, de facade, ni d'appel HTTP direct
     (garde-fous G3b appliqué à `Domain`, et par convention à `Application`
     aussi pour la testabilité).
   - S'il touche de l'argent : ouvre explicitement la transaction, vérifie
     l'idempotence en premier (voir
     [ADR-0025](../../../docs/adr/0025-design-idempotence.md)) si le module
     concerné l'a déjà implémentée, applique la règle métier, persiste via le
     port de repository, **écrit l'événement dans l'outbox dans la même
     transaction** (jamais `event()`/`dispatch()` — garde-fou G8).

4. **Si un nouvel événement de domaine apparaît** : l'ajouter au catalogue
   d'événements (`docs/architecture/` — catalogue à tenir à jour) avec son nom
   (`<module>.<action_passée>`, ex. `wallet.credited`) et son payload.

5. **Écrire le test unitaire** dans `packages/<module>/tests/Unit/`, avec des
   doublures des ports du `Domain` — aucune base de données nécessaire pour
   un cas d'usage bien isolé.

6. **Si le cas d'usage est exposé en HTTP** : le contrôleur dans
   `Presentation/Http/Controllers/` reste fin — il valide (Form Request),
   invoque le cas d'usage, façonne la réponse. Aucune règle métier dans le
   contrôleur. Voir la skill `contrat-api` (agent) pour la revue du contrat.

## Ne jamais faire

- Écrire une règle métier dans `Infrastructure/` ou `Presentation/`.
- Dispatcher un événement avant que la transaction ne soit validée.
- Référencer directement une classe `Domain`/`Application`/`Infrastructure`
  d'un **autre** module — passer par son `Contracts/` (garde-fou G3c).
