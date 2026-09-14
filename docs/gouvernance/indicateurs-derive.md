# Indicateurs de dérive

Source : `docs/specs/gouvernance-anti-derive.md` §15. Suivis en continu ; toute
tendance défavorable déclenche une revue du comité d'architecture.

| Indicateur | Cible | Où le lire | Dernière mesure |
| ---------- | ----- | ---------- | ---------------- |
| Dette de frontières (violations Deptrac) | Zéro | `make arch` | — (Phase 0 : 0 violation) |
| Colonnes PII dans `parties` | Zéro | `tests/Schema/PartiesPiiTest.php` (G4) | — (table pas encore créée) |
| Nombre d'exceptions actives | Tend vers zéro | `docs/gouvernance/registre-exceptions.md` | 0 |
| Capacités dans le Core vs valeur démontrée | Croissance justifiée, pas mécanique | `docs/adr/README.md` | 15 modules scaffoldés (spec §4), 0 lot métier livré |
| Versions de contrat en vol | Maîtrisées, dates de dépréciation connues | Fenêtre de dépréciation — à confirmer avant Lot 2 (ADR-0028) | — |
| Écarts de réconciliation financière | Tendent vers zéro | Réconciliation shadow mode (Lot 7) | — (pas encore de flux réel) |
| Taux d'accès au catalogue des capacités | Signe de réutilisation, pas de reconstruction | Catalogue des capacités (`docs/architecture/`, à créer avec Lot 1) | — |
| Garde-fous actifs vs en attente | Croît vers « tous actifs » à mesure des lots | `docs/architecture/garde-fous-inactifs.md` | 9 actifs / 7 en attente (2026-09-14) |

## Procédure de revue trimestrielle

1. Mettre à jour chaque ligne « Dernière mesure » avec une date.
2. Comparer à la mesure précédente : toute tendance défavorable (violations
   Deptrac > 0, exceptions qui augmentent, garde-fous qui restent
   `pending-code` au-delà de leur lot prévu) est portée à l'ordre du jour du
   comité.
3. Consigner les décisions prises en ADR si elles sont structurantes.
