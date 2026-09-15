# ADR-0041 — Conception Lot 5 : orchestration Notification, sans adaptateur concret

- **Statut** : Accepté — §4 (templates) remplacé par ADR-0043
- **Date** : 2026-09-15
- **Précise** : spec technique §6.7, §9.2–§9.5 (Notification, provider router,
  cas WhatsApp auto-hébergé)

## Contexte

Lot 5 livre normalement l'orchestration multi-canal **et** un premier
adaptateur concret (`WhatsAppSelfHostedAdapter`). L'utilisateur prépare
lui-même la documentation de cet adaptateur pour une intégration
ultérieure — la décision de ce tour est donc de livrer uniquement
l'orchestration : le port `NotificationChannelProvider`, le routage avec
fallback, les templates versionnés, le vocabulaire canonique
`NotificationStatus`, et la gestion des changements de statut entrants —
sans aucun adaptateur concret ni endpoint de callback réel. Un
`FakeNotificationChannelProvider` couvre les tests ; un futur adaptateur se
branche sur le même contrat sans toucher au reste.

## Décision

### 1. Pas d'abonnement automatique à l'outbox — API self-service uniquement

Le flux de référence du paiement (spec §10, étape 9 : « Le Notification
Core envoie les notifications nécessaires ») décrit un exemple de
composition possible, pas un mandat pour que le Core lui-même déclenche
des notifications automatiquement en réaction à `payment.status_changed`.
Faire cela par défaut reproduirait exactement l'erreur corrigée par
ADR-0037 (crédit de wallet automatique) : une conséquence métier qui
devrait être une composition explicite, pas un comportement implicite du
Core. `POST /v1/notifications` est donc la seule porte d'entrée — un
produit (ou sa propre logique applicative en réaction à un webhook
`payment.status_changed`) décide explicitement d'envoyer une notification.
Rien dans `notification` ne s'abonne à `OutboxEventPublished`.

### 2. Le port provider réutilise le registre générique de `core/support`

`Core\Support\Integration\Provider\ProviderRegistry`/`ProviderGateway`
(construit en Phase 0, déjà utilisé par `Core\Payment\Contracts\PaymentGateway`
et `PaymentGatewayRegistry` — ADR-0039) est un mécanisme générique, pas
spécifique au paiement : « chaque module qui a des adaptateurs (payment,
notification, …) définit son propre wrapper typé » (docblock de
`PaymentGatewayRegistry`, écrit en Phase 0 en anticipation de ce lot).
`Core\Notification\Contracts\NotificationChannelProvider extends ProviderGateway`
et `NotificationChannelProviderRegistry` suivent donc exactement le même
patron : un `ProviderCode` reste unique tous modules confondus
(`DuplicateProviderCodeException` protège déjà contre une collision entre
un futur `whatsapp-selfhosted` et un `provider-x` de paiement).

### 3. Fallback ≠ retry — un routeur à liste ordonnée, pas un unique provider

`Core\Payment\Contracts\PaymentProviderRouter::resolveProvider()` retourne
un seul `ProviderCode` (un channel = un provider). La spec §9.3 décrit un
besoin différent pour la notification : une **chaîne de fallback**
(primaire → secondaire → …) essayée **synchrone­ment**, au moment de
l'envoi — pas un retry différé sur la même cible (ce que fait déjà le
Webhook du Lot 4 avec `next_retry_at`/Horizon `release()`). Le port est
donc `NotificationProviderRouter::resolveProviders(): list<ProviderCode>`
(pluriel, ordonné), et `SendNotificationUseCase` boucle dessus : un
provider qui échoue à l'envoi fait immédiatement essayer le suivant, sans
jamais attendre. Chaque tentative est tracée (table
`notification_deliveries`, `attempt` incrémental) pour l'audit — même sans
provider réel, le mécanisme de fallback est entièrement testable avec
plusieurs `FakeNotificationChannelProvider`.

### 4. `NotificationStatus` et les deux enums de template sont hors couches

Comme `Core\Payment\PaymentStatus` (pas `Domain\Model\PaymentStatus`,
malgré ce que `CanonicalStatusTest.php` supposait à tort — corrigé au
passage), `NotificationStatus`, `NotificationTemplateKind` et
`NotificationTemplateStatus` vivent directement sous `packages/notification/src/`,
hors de `Domain/Application/Infrastructure/Presentation` : Deptrac ne les
couvre alors dans aucune des quatre couches, donc ni `Contracts`
important `Domain`, ni `Domain` important `Contracts`, ne peut jamais se
produire pour ce type — le seul moyen de partager un vocabulaire fermé,
pur PHP, entre toutes les couches sans violer G3b/la pureté de `Contracts`.

`NotificationStatus` encode aussi sa propre machine à états
(`canTransitionTo()`) : `queued → {sent, failed}`, `sent → {delivered,
failed}`, `delivered → {read}` ; `read` et `failed` sont terminaux. C'est
une vraie règle de domaine (pas seulement documentaire), vérifiée par
`HandleNotificationStatusChangedUseCase` avant d'appliquer un changement de
statut entrant.

### 5. `to_party_ref` est un identifiant de corrélation, jamais une donnée de contact

`parties` interdit toute colonne PII (G4). `to_party_ref` (repris tel quel
du corps de la spec, §13) sert donc à retrouver/créer un `Party` de
corrélation (`FindOrCreatePartyCommand`, kind `notification_recipient` —
même mécanisme que `payment_recipient` au Lot 3) mais **ne** contient
**jamais** l'adresse réelle (numéro, email). La résolution de l'adresse
de livraison réelle à partir de cette référence est une responsabilité du
futur adaptateur (ou du produit appelant), pas du Core — non modélisée
ici pour ne pas présumer d'un mécanisme que l'utilisateur n'a pas encore
spécifié.

### 6. Aucun endpoint de callback, aucun adaptateur concret

`HandleNotificationStatusChangedUseCase` (l'équivalent Application de
`HandlePaymentStatusChangedUseCase`) existe et est testé, prêt à être
appelé par le contrôleur webhook d'un futur adaptateur — exactement comme
`MyCoolPayWebhookController` appelle `HandlePaymentStatusChangedUseCase`.
Sans adaptateur réel, aucun contrôleur HTTP de callback n'est construit :
en écrire un maintenant obligerait à deviner une forme de payload
arbitraire, exactement le genre de dette que ce lot doit éviter.

## Conséquences

- Un futur `WhatsAppSelfHostedAdapter` n'a que deux choses à faire pour
  s'intégrer : implémenter `NotificationChannelProvider`, se tagger
  `provider.gateway` dans son propre service provider, et écrire un
  contrôleur webhook qui appelle `HandleNotificationStatusChangedUseCase`
  — rien dans le reste du Core ne change.
- G6b (vocabulaire canonique) s'active pour `NotificationStatus` dès ce
  commit ; `CanonicalStatusTest` n'est plus vacueux ni pour le paiement ni
  pour la notification.
- Reste explicitement hors périmètre : tout adaptateur concret (WhatsApp
  ou autre), le canal `auto` (sélection du meilleur canal pour un
  destinataire), la résolution d'adresse de contact réelle, et tout
  déclenchement automatique de notification depuis un événement outbox.
