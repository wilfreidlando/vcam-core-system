# Réponse à l'avis d'architecte du 2026-09-14

**Objet** : décisions prises sur chaque point de
[avis-architecte-2026-09-14.md](avis-architecte-2026-09-14.md) et de
[guides/architecture-integration-externe.md](guides/architecture-integration-externe.md).
**Décideurs** : Tech Lead (wilfreidlando), sur proposition de l'agent d'implémentation.

La revue était utile : elle a mis au jour un **vrai trou dans les garde-fous**
(§3.2), confirmé par une sonde avant correction. Ses recommandations les plus
lourdes ont en revanche été refusées ou différées, parce qu'elles concevaient des
abstractions avant d'avoir le moindre cas réel pour les valider — ce que notre
propre gouvernance interdit (porte n°1 : au moins deux besoins réels).

## Décisions

| § | Point soulevé | Décision | Trace |
| - | ------------- | -------- | ----- |
| 3.1 | 15 modules créés au jour 0 : frontières hypothétiques | **Conservé**. Le découpage vient de la spec technique §4, pas d'une projection. Les dépendances des modules hors MVP restent révisables par ADR avant leur premier code. | [ADR-0002](../adr/0002-contextes-bornes-packages-composer.md) |
| 3.2 | `Admin` peut lire les internes des autres modules | **Accepté, et plus large que signalé** : *n'importe quel* module pouvait utiliser le `Domain` d'un autre. Deptrac ne distingue pas « mon Domain » du « Domain d'un autre » ; sonde confirmée (0 violation). Corrigé par le garde-fou **G3c**. | [ADR-0027](../adr/0027-read-models-et-acces-inter-modules.md) |
| 3.3 | `Support` risque de devenir un fourre-tout | **Accepté**. Manifeste fermé + garde-fou **G3d** (aucune persistance dans le shared kernel). | [ADR-0020](../adr/0020-shared-kernel-support.md) |
| 3.4 | CQRS implicite, propriété des read models | **Accepté** : chaque module publie ses read models dans son `Contracts/` ; aucune jointure SQL entre contextes ; `Admin` compose via les contrats. | [ADR-0027](../adr/0027-read-models-et-acces-inter-modules.md) |
| 3.5 | Une outbox par module | **Refusé**. Dans un monolithe sur une seule base, une opération Wallet + Payment reste *une* transaction PostgreSQL : des tables séparées n'apportent aucune indépendance de commit. Le schéma proposé plaçait en outre l'outbox dans `Support`, contredisant §3.3. Table unique `event_outbox` (spec §6.8), possédée par `Events`. | [ADR-0024](../adr/0024-outbox-table-unique-module-events.md) |
| 3.6 | Idempotence à livrer au Lot 0 | **Design figé maintenant, code au Lot 2** avec les premières mutations de wallet (premier consommateur réel). Le garde-fou G7 empêche déjà toute route monétaire sans idempotence. | [ADR-0025](../adr/0025-design-idempotence.md) |
| 3.7 | Stratégie de versionnement d'API | **Accepté** : préfixe d'URL `/v1`, déjà prévu par la spec §13 ; aligné dans `bootstrap/app.php`. Fenêtre de dépréciation proposée, à confirmer avant Lot 2. | [ADR-0028](../adr/0028-versionnement-api-par-url.md) |
| 3.8 | Mutation testing absent | **Déjà en place** : job Infection dans `.github/workflows/nightly.yml`, cantonné au domaine financier, activé automatiquement au premier code du Ledger. | [ADR-0022](../adr/0022-chaine-qualite.md) |
| 3.9 | `.deptrac.cache` versionné | **Accepté** : ajouté au `.gitignore` (il n'avait jamais été commité). | `.gitignore` |
| 4 | Framework d'intégration complet au Lot 0 | **Refusé au Lot 0 ; principes retenus en ADR et garde-fous posés** (G6c actif). Construit au Lot 3 à partir de MTN et Orange. Mode « succès simulé » interdit hors sandbox ; pas de `support.adapter_logs` ; pas d'exposition des logs d'appel aux applications. | [ADR-0026](../adr/0026-integration-providers-externes.md) |

## Ce que la revue a changé concrètement

- Nouvelle règle PHPat `ModulesMeetOnlyThroughContracts` (G3c), une règle par
  module ; `ComposerGraphTest` échoue si un module du graphe n'a pas la sienne.
- Nouvelle règle PHPat `SharedKernelStaysTechnical` (G3d).
- Nouvelle règle PHPat `OutboundHttpIsCentralised` (G6c).
- Commentaire de `deptrac-layers.yaml` corrigé : il affirmait à tort que l'accès
  inter-modules passait forcément par `Contracts/`.
- `make guard-test` exige désormais que chaque porte rejette sa sonde **avec le
  message du garde-fou**, et non pour une raison annexe.
- Six ADR supplémentaires (0024 à 0028, plus la mise à jour de 0020).
