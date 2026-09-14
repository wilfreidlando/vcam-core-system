---
name: dod-lot
description: Check a delivery lot against its Definition of Done before declaring it complete. Use when asked "is Lot N done?" or before a sprint-end demo.
allowed-tools: Read, Bash(make *), Bash(docker compose *)
---

# Definition of Done d'un lot

Source : `docs/specs/plan-delivery-mvp.md` §9. Un lot n'est « fini » que si
**tous** les points suivants sont vrais — pas la majorité, tous.

## Checklist générale (tout lot)

- [ ] Code revu par au moins un pair.
- [ ] Frontières Deptrac respectées : `make arch` vert.
- [ ] Tests unitaires et d'intégration passants : `make test` vert.
- [ ] Contrats d'API publiés en OpenAPI et couverts par des tests de contrat
      (si le lot expose une route).
- [ ] Idempotence vérifiée sur toutes les mutations monétaires concernées.
- [ ] Événements émis via l'outbox uniquement — jamais hors transaction.
- [ ] Opérations sensibles présentes dans l'audit, corrélées.
- [ ] Documentation du module à jour (README du package + endpoints).
- [ ] `make guard-test` vert — chaque garde-fou concerné par ce lot a été
      **vu échouer** au moins une fois sur une vraie violation, pas seulement
      écrit.
- [ ] Démo réalisée en fin de sprint.

## Exigence spécifique au Lot 2 (Money)

- [ ] Aucune écriture ledger mutable.
- [ ] Solde matérialisé toujours égal à la somme dérivée des écritures.
- [ ] Aucun solde négatif non voulu sous concurrence (test de concurrence réel
      sur PostgreSQL, pas SQLite).
- [ ] Rejeu d'une opération identique sans double effet.
- [ ] `tests/Invariants/LedgerInvariantsTest.php` : plus aucun
      `markTestIncomplete`, tous les tests exécutent une vraie assertion.

## Procédure

1. Identifier le lot concerné et son contenu exact
   (`docs/specs/plan-delivery-mvp.md` §4).
2. Exécuter la checklist générale, cocher chaque point avec une preuve
   (commande exécutée, fichier, sortie de `make`).
3. Si le lot est le Lot 2, ajouter la checklist spécifique Money.
4. Vérifier `docs/architecture/garde-fous-inactifs.md` : les garde-fous prévus
   pour être activés par ce lot (colonne « Activé par ») sont-ils passés en
   `active` dans `tests/Architecture/GuardRegistry.php` ? Sinon, le lot n'est
   pas complet même si le code fonctionne — c'est la trace qui manque.
5. Produire un verdict : lot terminé, ou liste précise de ce qui manque.

## Ne jamais faire

- Déclarer un lot terminé parce que « ça marche en local » sans que
  `make ci` et `make guard-test` soient verts.
- Ignorer un `markTestSkipped`/`markTestIncomplete` restant sur un garde-fou
  que ce lot était censé activer.
