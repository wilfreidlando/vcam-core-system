---
name: nouvel-adaptateur-provider
description: Scaffold a new external provider adapter (payment or notification) with the canonical-status mapping and anti-corruption boundaries wired correctly. Use when asked to integrate a new provider (MTN MoMo, Orange Money, WhatsApp, or a future one).
allowed-tools: Read, Write, Edit, Grep
---

# Nouvel adaptateur provider

Avant d'utiliser cette skill, relis
[ADR-0026](../../../docs/adr/0026-integration-providers-externes.md) : le
socle d'intégration (`Core\Support\Integration`) se construit **à partir des
providers réels du MVP** (MTN et Orange), pas par anticipation. Si c'est le
premier ou le deuxième adaptateur du projet, une partie de cette skill consiste
à *extraire* ce qui est mutualisable, pas seulement à le consommer.

## Étapes

1. **Situer le module** : `packages/payment/src/Infrastructure/Provider/<Provider>/`
   ou `packages/notification/src/Infrastructure/Provider/<Provider>/`.

2. **Vérifier le port existant** : `PaymentProvider` ou
   `NotificationChannelProvider` dans `Domain/Port/` (spécification technique
   §9.1–9.2). Ne pas créer un nouveau port par provider — implémenter
   l'existant.

3. **Créer le mapper de statuts** (`<Provider>StatusMapper`), seul endroit
   autorisé à contenir les littéraux de statut bruts du provider (documenter
   chaque valeur avec sa source : doc officielle du provider si disponible).
   Traduit vers l'enum canonique :
   - Paiement : `pending, processing, succeeded, failed, expired, refunded`
   - Notification : `queued, sent, delivered, read, failed`

4. **Ajouter le provider au registre PHPStan des littéraux interdits**
   (`tools/PhpStan/Rules/ForbiddenProviderStatusLiteralRule.php`,
   `PROVIDER_VOCABULARY`) : chaque statut brut du provider → le namespace de
   son mapper. Sans cette entrée, le garde-fou **G6** ne peut pas détecter une
   fuite de ce vocabulaire précis.

5. **Implémenter l'adaptateur** (`<Provider>Adapter implements PaymentProvider`
   ou `NotificationChannelProvider`) :
   - Aucun appel HTTP direct — passe par `Core\Support\Integration`
     (garde-fou G6c). S'il n'existe pas encore, c'est le signal de le
     construire maintenant à partir de ce que ce provider et le précédent ont
     réellement en commun (auth, headers, gestion des erreurs).
   - `supports(Method $method, string $country): bool` explicite.
   - En cas de panne provider : rejet propre (exception typée, statut
     `failed`/`pending`) — **jamais** un succès simulé hors sandbox
     (rejeté explicitement en ADR-0026).

6. **Câbler dans le registre de providers** (`ProviderRegistry` /
   `PaymentRouter`) : tag Laravel, pas de `match($provider)` dans le code
   applicatif.

7. **Tests** :
   - Unit sur le `StatusMapper` (table de correspondance brut → canonique).
   - Contract avec `Http::fake()` (ou l'équivalent une fois
     `Core\Support\Integration` en place) : vérifie la forme exacte de la
     requête (URL, headers, corps, signature).
   - Un simulateur sandbox reproduisant les statuts et callbacks du provider,
     y compris les cas d'échec et timeout (spécification technique §18).

## Ne jamais faire

- Laisser un statut brut (`'SUCCESSFUL'`, `'INITIATED'`…) apparaître en dehors
  du `StatusMapper` de ce provider.
- Construire un DSL de templates déclaratifs ou un catalogue d'endpoints avant
  d'avoir au moins deux providers réels à comparer — voir la section
  « rejeté » de l'ADR-0026.
- Exposer le statut brut ou le payload provider à l'application consommatrice.
