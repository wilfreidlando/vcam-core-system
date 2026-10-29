# Plan de livraison complet — de l'état au 2026-10-29 à « tous les modules livrés »

- **Date** : 2026-10-29 · **Statut** : plan de travail (ne décide rien : les décisions
  structurantes sont des ADR, voir §6)
- **Références** : [plan-delivery-mvp.md](plan-delivery-mvp.md) §P3–P7,
  [carte-des-capacites-produit.md](carte-des-capacites-produit.md),
  [gouvernance-anti-derive.md](gouvernance-anti-derive.md) §4, ADR-0068, 0080, 0084, 0090, 0097, 0098

**Règle de lecture.** Un module est **livré** quand il passe sa *définition de livré* (§2) :
code conforme à ses ADR, garde-fous verts, tests (nominal + refus + isolation), écran de console
quand l'ADR en promet un, documentation d'intégration, et ligne à jour dans la carte des
capacités. Ce plan est **terminé** quand les 17 modules de §1 sont « livré » ou « refusé par
ADR » — il n'existe pas d'autre état final.

## 1. État réel des modules (mesuré dans le code, 2026-10-29)

| Module | Pilier | Fichiers `src/` | Contrats | Migrations | Tests propres | État | Reste à livrer |
|---|---|---|---|---|---|---|---|
| `support` | plateforme | 97 | — | — | 27 | **livré** | partitions/horloges de test (lots 15–16) |
| `identity` | plateforme | 208 | 83 | 15 | 34 | **livré** | permissions de lecture usage/plans pour `org_finance`/`org_support` (lot 14) |
| `audit` | plateforme | 16 | 10 | 5 | 3 | **livré** | — |
| `events` | plateforme | 12 | 7 | 5 | 1 | **livré** | relais réveillé après commit (lot 15) |
| `webhook` | plateforme | 102 | 46 | 9 | 21 | **livré** | événements de commerce abonnables ✔ (ADR-0097) |
| `notification` | plateforme | 112 | 57 | 8 | 15 | **livré** (WhatsApp) | quota ✔ ; adaptateurs e-mail/SMS = extension ADR-0090 |
| `payment` | plateforme | 153 | 57 | 14 | 32 | **livré** (MyCoolPay) | MTN/Orange directs = extension ; rapprochement prestataire (lot 18) |
| `parties` | grand livre | 12 | 5 | 1 | 2 | **livré** | — |
| `ledger` | grand livre | 70 | 32 | 8 | 12 | **livré** | niveaux B/C du rapprochement (lot 18) |
| `wallet` | grand livre | 67 | 27 | 3 | 8 | **livré** | — |
| `billing` | commerce | 303 | 103 | 11 | 8 | **livré** (lots 10–12) | indicateurs ✔ ; instantanés quotidiens, `lost_at`, rendu du reçu, PDF (lot 14, ADR-0094 §14) |
| `portal` | commerce | 48 | 3 | 1 | 0 propres (couvert par `tests/Integration`) | **livré** (lot 13) | connexion directe par code (lot 17) ; amendement ADR-0098 |
| `admin` | pilotage | 26 | 1 | 0 | 0 propres (couvert par `tests/Feature`) | **partiel** | lot 14 : instantanés + courbes, checklist d'onboarding, droits |
| `developer-platform` | plateforme | 1 | 1 | 0 | 0 | **scaffold** | lot 16 (ADR-0096) |
| `payout` | grand livre | — | — | — | — | **absent** | lot 19 (ADR-0079) |
| `fees` | grand livre | 1 | 1 | 0 | 0 | **scaffold** | **décision requise** (§6, D1) |
| `storage` | plateforme | 1 | 1 | 0 | 0 | **scaffold** | **décision requise** (§6, D1) |

Planifié et vérifié : `events:relay`, `admin:reconcile-stuck-payments`, `invariants:check`,
`audit:seal`/`audit:verify`, `webhooks:purge-expired-secrets`, `webhook:sweep`, `model:prune`,
`notification:redact-expired` et `billing:advance-subscriptions` sont dans `bootstrap/app.php`.
CI : `.github/workflows/ci.yml` et `nightly.yml`. Observabilité : deux tableaux Grafana et
`alerts/core-alerts.yaml`.

## 2. Définition de « livré » (identique pour tous les modules)

1. **Conformité** : chaque décision des ADR du module est dans le code ; aucune capacité hors ADR (sinon ADR d'amendement).
2. **Garde-fous** : `make ci` et `make guard-test` verts ; G3c/G15/G16 couvrent ses routes et ports.
3. **Tests** : nominal, refus, idempotence, isolation entre projets, concurrence quand l'argent ou un compteur est en jeu.
4. **Flux** : le parcours bout en bout qui traverse le module est joué avec une horloge pilotée (`SaasLifecycleFlowTest` est le modèle).
5. **Console** : un écran par geste d'exploitation promis par l'ADR (§ « console »), derrière sa permission, audité.
6. **Documentation** : `docs/integration/` à jour, `carte-des-capacites-produit.md` passée de ◐ à ●, ADR cités dans les messages de commit.
7. **Exploitation** : tâche planifiée déclarée, métrique/alerte si le module peut échouer en silence.

## 3. Lots restants (ordre qui ne casse rien)

Estimations en jours de développeur senior, ±30 %, **non contraignantes** (ADR du plan §P3). Chaque lot se termine vert.

| # | Lot | Contenu | Modules livrés à la fin | ADR | Est. |
|---|---|---|---|---|---|
| **S** | **Stabiliser le lot 12–13** | suite complète verte ; vues d'accessibilité restantes ; amendement ADR-0098 ; correctifs des revues (renouvellement payé d'avance ✔, plan du contrat ✔, indicateurs ✔) ; carte des capacités et `CLAUDE.md` à jour | `billing` (usage), `portal` | 0074, 0075, 0097, **0098** | 1 j |
| **14** | **Console de portefeuille** | `billing_daily_metrics` + job nocturne idempotent + courbes SVG serveur ; checklist d'onboarding calculée ; droits de lecture alignés sur ADR-0080 §1 ; fiche client ✔ ; écran Créances (lecture seule) ; `lost_at` indexé | `admin` | 0080 | 8 j |
| **15** | **Exploitation** | débit par projet/clé/classe + en-têtes `RateLimit-*` ; métriques par projet et alertes ; relais de l'outbox après commit ; listings par curseur pour paiements/usage/notifications/endpoints ; **export** par projet (JSONL, lien signé 15 min, `exports:write`) | `events`, `support` | 0085, 0088, 0090 | 10 j |
| **16** | **Contrat et expérience développeur** | `developer-platform` : registre de capacités + `GET /v1/capabilities` + test registre↔OpenAPI ; pages de démarrage exécutées ; politique `Deprecation`/`Sunset` ; `api_version` dans les événements ; horloges de test | `developer-platform` | 0086, 0087, 0096 | 7 j |
| **17** | **Recette et bascule** | connexion directe par code (portail) ; recette R-01…R-15 automatisée de bout en bout ; guide d'intégration unique vérifié sur une application de démonstration (30 min chronométrées) ; plan de bascule exécutable ; retrait des secrets de la plateforme transitoire | `portal`, plan d'extinction | 0084, 0089, 0075 §2 | 8 j |
| **18** | **Rapprochement prestataire** | capacités `listTransactions`/`balance` des adaptateurs ; niveaux B et C ; écrans | `payment`, `ledger` | 0078 | 10 j |
| **19** | **Versements sortants** | module `payout`, adaptateur testable, double validation, plafonds, destination chiffrée | `payout` | 0079 | 15 j |
| **D** | **Décisions de périmètre** | ADR pour `fees` et `storage` (§6) ; ADR pour chaque extension demandée (relances, codes promo, e-mail) | `fees`, `storage` | 0048, 0068, 0090 | 1 j |

Chemin critique vers la **mise en service** : S → 14 → 17 (≈ 17 j). Les lots 15 et 16 peuvent partir
en parallèle dès la fin de S (fichiers disjoints). 18 et 19 ne conditionnent pas la bascule : ils
sont livrés ensuite, mais le plan n'est « terminé » qu'avec eux (ou leur refus par ADR).

## 4. Ce que chaque lot exige comme vérification

- **S** : `make ci` + `guard-test` + `tools/checks/usage-concurrency.sh` ; recette `SaasRecetteEndToEndTest` et cycle `SaasLifecycleFlowTest` ; audit d'accessibilité sans constat bloquant.
- **14** : tests de définition des indicateurs (MRR à intervalles mixtes, attrition, créances par ancienneté, encaissé du mois), cloisonnement `org_viewer` A / projet de B, rejouabilité du job d'instantanés sur une plage, test IDOR généré sur chaque route.
- **15** : charge staging (200 req/s mixtes 15 min, ADR-0090), deux workers du relais ne livrent jamais deux fois, export refusé hors portée, 429 `rate-limit-exceeded` avec `Retry-After`.
- **16** : le registre de capacités et `openapi/v1.json` ne peuvent pas diverger (test CI) ; chaque page de démarrage est un test.
- **17** : R-01…R-15 verts sur staging ; coexistence 14 jours sans appel métier à la plateforme transitoire ; installation du kit chronométrée.
- **18/19** : niveaux B/C sur un jeu de relevés avec écart volontaire ; versement refusé au-delà du plafond, à un seul compte, sans motif.

## 5. Documents à mettre à jour à chaque fin de lot

`CLAUDE.md` (état des lots), `docs/specs/carte-des-capacites-produit.md` (◐ → ●),
`docs/specs/plan-delivery-mvp.md` (§P3 « état »), `docs/adr/README.md`, `docs/integration/*`,
`openapi/v1.json` et `admin.json`, `docs/architecture/garde-fous-inactifs.md` si un garde-fou s'active.
**Constat à corriger tout de suite** : la carte des capacités v2.4 montre encore les lots 12 et 13
en ◐ alors que le code est livré.

## 6. Décisions qui ne sont pas des détails (à écrire en ADR, une par ligne)

| # | Décision | Position recommandée | Pourquoi |
|---|---|---|---|
| **D1** | `fees` et `storage` : construire ou retirer ? | **Retirer les scaffolds** (ou les marquer « refusé » dans la liste négative) tant qu'aucun produit réel n'en a besoin | ADR-0048/0068 les ont mis hors périmètre ; porte 1 de la gouvernance (≥ 2 produits réels) non franchie. Un module vide « livré » serait un mensonge |
| D2 | Relances d'impayés (dunning) | **Hors Core** : le projet notifie à partir de `subscription.renewal_due` / `past_due` (ADR-0089 §1) | Composition implicite entre modules interdite (ADR-0037/0041) ; la porte « générique » est discutable |
| D3 | Codes promo / remises | Extension ADR-0090 **après** un cas d'usage réel ; en v1 : plan privé remisé (ADR-0071 §6) | Pas de besoin avéré |
| D4 | Canal e-mail / SMS | Adaptateur + ADR **si** un projet le demande (la plateforme transitoire envoyait des e-mails d'expiration) | ADR-0090 : un adaptateur = une entrée de registre + ADR |
| D5 | Partitions mensuelles d'`usage_records` | **Reportées au lot 15 en tant que tâche d'exploitation** ; le schéma livré n'en déclare aucune (choix du propriétaire : migrations Laravel, aucune contrainte en base) | ADR-0090 §1 les demandait ; écart à consigner dans ADR-0098 |
| D6 | « Encaissé » : exclure les reclassements de règlement | Lire le grand livre par type de transaction, pas par compte | Un reclassement débite deux fois un compte de règlement (revue de code) |

## 7. Traçabilité des écarts déjà identifiés

Écarts à **corriger** (déjà faits dans cette session) : lien à usage unique dans les journaux ; kit
de webhook qui échoue ouvert ; jauge dont la limite restait gelée ; clé d'idempotence réutilisée
pour un autre appel ; première facture payée sans activation ; renouvellement payé d'avance
considéré impayé ; plan du contrat inchangé après changement d'offre ; fenêtres d'usage closes
comptées comme pression.
Écarts à **assumer par ADR-0098** : souscrire, renouveler et reprendre le renouvellement depuis le
portail (ADR-0075 §3 ne les liste pas) ; événement `subscription.resumed` réutilisé pour la reprise
du renouvellement (ADR-0072 §9 le réservait à la sortie de pause) ; création d'un abonnement par
un opérateur depuis la fiche client ; absence de partition (D5) ; retrait de « mettre à jour
email/téléphone » du portail (le projet reste propriétaire de l'identité).
Retirés pour ne pas dériver : pause libre-service dans le portail ; ajustement manuel de l'usage
en console.
