# core/support — shared kernel

Tous les modules peuvent dépendre de ce package ; lui ne dépend d'aucun module.
C'est exactement le profil du package qui finit en fourre-tout. Ce README est
donc un **manifeste fermé** : ce qui n'y est pas listé n'a rien à faire ici.

Décision de référence : [ADR-0020](../../docs/adr/0020-shared-kernel-support.md).

## Ce qui a le droit de vivre ici

| Primitive | Emplacement | Statut |
| --------- | ----------- | ------ |
| `Clock` (port) et `SystemClock` (UTC) | `src/Clock.php`, `src/SystemClock.php` | Livré |
| `Environment` (sandbox / live) | `src/Environment.php` | Livré |
| Réponses d'erreur RFC 7807 | `src/Http/ProblemDetails.php` | Livré |
| Middleware `CorrelationId` | `src/Http/Middleware/CorrelationId.php` | Livré |
| `Money` — enveloppe de brick/money | `src/Money/` | Lot 2 — [ADR-0021](../../docs/adr/0021-wrapper-money.md) |
| Middleware d'idempotence + port `IdempotencyStore` | `src/Idempotency/` | Lot 2 — [ADR-0025](../../docs/adr/0025-design-idempotence.md) |
| Appelant HTTP sortant d'intégration | `src/Integration/` | Lot 3 — [ADR-0026](../../docs/adr/0026-integration-providers-externes.md) |

## Ce qui est interdit ici

- **Toute persistance** : Eloquent, query builder, `DB`, `Schema`, `Redis`
  (garde-fou G3d). Une primitive qui a besoin de stocker déclare un port ; le
  shell applicatif l'implémente.
- **Toute dépendance vers un module** (G1).
- **Toute règle métier** et toute classe nommée d'après une capacité (paiement,
  wallet, frais, notification…).
- **Tout vocabulaire provider** (MTN, Orange, WhatsApp…).

## Ajouter une primitive

Une primitive n'entre que si elle est utilisée par **au moins deux modules**,
sans règle métier ni connaissance d'un provider. La PR qui l'ajoute met à jour
ce tableau **et** l'ADR-0020 ; elle passe par le comité d'architecture.
