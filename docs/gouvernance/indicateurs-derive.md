# Indicateurs de dérive

Source : `docs/specs/gouvernance-anti-derive.md` §15. Suivis en continu ; toute
tendance défavorable déclenche une revue du comité d'architecture.

| Indicateur | Cible | Où le lire | Dernière mesure |
| ---------- | ----- | ---------- | ---------------- |
| Dette de frontières (violations Deptrac) | Zéro | `make arch` | 0 violation (2026-10-27) |
| Colonnes PII dans `parties` | Zéro | `tests/Schema/PartiesPiiTest.php` (G4) | 0 (2026-10-27) |
| Nombre d'exceptions actives | Tend vers zéro | `docs/gouvernance/registre-exceptions.md` | 0 |
| Capacités dans le Core vs valeur démontrée | Croissance justifiée, pas mécanique | `docs/adr/README.md` | 12 modules portés (hors les 3 scaffolds hors périmètre : `fees`, `storage`, `developer-platform` — ADR-0048 amendé par ADR-0068) ; carte des capacités : 36 **●** / 13 **◐** / 9 **○** / 3 **✗** (2026-10-28) |
| Versions de contrat en vol | Maîtrisées, dates de dépréciation connues | `openapi/v1.json` (G9, ADR-0086) | 1 version publiée (`/v1`), 0 en dépréciation ; fenêtre de 12 mois après la majeure suivante (2026-10-27) |
| Écarts de réconciliation financière | Tendent vers zéro | Réconciliation `checkStatus()` (ADR-0067) ; bascule (Lot 17) | — (pas encore de flux réel) |
| Taux d'accès au catalogue des capacités | Signe de réutilisation, pas de reconstruction | Catalogue des capacités (ADR-0087, lot 16) | — (catalogue non construit) |
| Garde-fous actifs vs en attente | Croît vers « tous actifs » à mesure des lots | `docs/architecture/garde-fous-inactifs.md` | 23 actifs / 1 en attente (2026-10-28) — 22 sondés par `make guard-test` (G1 et G1b partagent une sonde) |

## Procédure de revue trimestrielle

1. Mettre à jour chaque ligne « Dernière mesure » avec une date.
2. Comparer à la mesure précédente : toute tendance défavorable (violations
   Deptrac > 0, exceptions qui augmentent, garde-fous qui restent
   `pending-code` au-delà de leur lot prévu) est portée à l'ordre du jour du
   comité.
3. Consigner les décisions prises en ADR si elles sont structurantes.
