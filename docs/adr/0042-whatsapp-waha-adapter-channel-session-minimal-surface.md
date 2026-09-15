# ADR-0042 — Adaptateur WhatsApp via WAHA auto-hébergé, surface minimale

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Précise** : ADR-0041 (Lot 5 — orchestration Notification, sans
  adaptateur concret)

## Contexte

L'utilisateur auto-héberge [WAHA](https://waha.devlike.pro) (WhatsApp HTTP
API, édition **Plus** — plusieurs sessions en parallèle) et veut l'intégrer
au Core. C'est l'adaptateur WhatsApp qu'ADR-0041 avait délibérément laissé de
côté (orchestration livrée, aucun adaptateur concret). Portée explicitement
demandée, rien de plus :

- **Liaison d'un téléphone** : QR code, code de couplage (pairing/request
  code), capture d'écran de la session.
- **Envoi de message** : texte, image, fichier.

## Décision

### 1. Tout reste dans `packages/notification`

La liaison de session WhatsApp fait partie du provisionnement d'un provider
de canal, pas un domaine à part — porte 2 de la gouvernance (générique)
satisfaite en la rattachant au concept existant de « provider de canal »
plutôt qu'en créant un module pour un seul provider.

### 2. Une seule classe, deux contrats — pas de nouveau registre

`WahaAdapter` implémente `NotificationChannelProvider` (existant,
send/getStatus/handleDeliveryCallback/supports) **et** un nouveau port
`Core\Notification\Contracts\ChannelSessionProvider`
(startSession/requestPairingCode/getQrCode/getScreenshot). Résolu via le
registre **existant** `NotificationChannelProviderRegistry` (même
`ProviderCode` `whatsapp-waha`, même tag `provider.gateway`) ; les use cases
de session font `instanceof ChannelSessionProvider` avant d'appeler les
méthodes de session — réutilise `TaggedProviderRegistry`/`ProviderGateway`
tels quels (`packages/support/src/Integration/Provider/`), aucun mécanisme
nouveau.

`ChannelSessionProvider` est délibérément **hors** de
`NotificationChannelProvider` : envoyer/consulter un statut est un concept
universel à tout canal, lier un appareil ne l'est pas (un adaptateur SMS ou
email n'a rien de tel).

### 3. Aucune persistance de session côté Core — proxy fin, stateless

Décision révisée en cours d'implémentation, à la demande explicite de
l'utilisateur : **le Core ne persiste rien sur une session** — ni table, ni
statut canonique de session, ni création paresseuse suivie en base. Le
`sessionRef` est fourni par l'appelant à chaque requête et transmis tel quel
au provider résolu ; le provider lui-même est l'unique source de vérité sur
l'état d'une session. `startSession()` est appelé en interne (idempotent,
jamais exposé comme son propre endpoint) avant chaque opération de session
pour éviter à l'appelant de séquencer une étape « créer » séparée.

Ce choix garde le contrat d'API stable au changement de provider : si demain
un autre fournisseur WhatsApp remplace WAHA, seul l'adaptateur change —
jamais les endpoints ni leurs appelants. C'est aussi pourquoi aucun
`ChannelSessionStatus` canonique n'a été introduit : sans état persisté côté
Core, il n'y a rien à faire dériver d'un vocabulaire brut vers un vocabulaire
canonique pour cette partie (G6 reste respecté : `WahaAdapter` reste le seul
endroit qui connaît la forme des réponses WAHA, mais il n'y a pas de machine
à états à protéger).

### 4. Le canal d'envoi (`send()`) résout aussi sa session par la requête

`variables['session']` — si l'appelant le fournit — sinon
`extras.default_session` (config), sinon `"default"` (le nom de session par
défaut de WAHA). Un Project avec une seule session liée n'a donc jamais
besoin de préciser laquelle.

### 5. Contenu du message : `OutboundMessage` gagne un champ `body`

`SendNotificationUseCase` chargeait déjà `$template->body` (jsonb) mais ne le
transmettait jamais à l'adaptateur — un vrai trou, invisible tant qu'aucun
adaptateur n'existait : sans ce champ, aucun adaptateur ne peut savoir *quoi*
envoyer. Convention de contenu (interprétée par l'adaptateur, jamais validée
par le Core) :

```json
{"type": "text", "text": "Bonjour {{name}}, ..."}
{"type": "image", "caption": "...", "media_url": "...", "filename": "...", "mimetype": "image/jpeg"}
{"type": "file",  "caption": "...", "media_url": "...", "filename": "...", "mimetype": "application/pdf"}
```

`{{variable}}` interpolé par l'adaptateur depuis `OutboundMessage::variables`.

### 6. Le numéro de téléphone voyage dans `variables`, jamais dans `recipientRef`

`recipientRef` reste un identifiant de corrélation pur (G4 — `parties` ne
porte aucune PII, ADR-0041 §5). Le numéro réel voyage dans
`variables['phone_number']`, fourni par l'app métier à chaque envoi, jamais
persisté par le Core (`notifications`/`notification_deliveries` ne stockent
pas `variables`). `WahaAdapter::send()` lève `MissingRecipientPhoneNumber` si
absent.

### 7. Auth du webhook entrant : secret partagé en header

WAHA est configuré pour envoyer un header custom (`X-Webhook-Secret`) sur ses
callbacks ; `WahaWebhookController` compare en temps constant
(`hash_equals`) contre `providers.notification.whatsapp_waha.extras.webhook_secret`.
Pas de vérification HMAC : le schéma exact de signature WAHA n'est pas assez
documenté pour le câbler correctement sans deviner.
`Core\Support\Integration\Signing\HmacSha256WebhookVerifier` (déjà présent,
déjà testé, inutilisé dans `core/support`) reste disponible pour un futur
durcissement si l'instance WAHA de l'utilisateur expose du HMAC.

### 8. Mapping des `ack` de message (best-effort)

`-1 → failed`, `0`/`1 → sent`, `2 → delivered`, `≥3 → read` — vocabulaire
`ack` du protocole WhatsApp Web (partagé par les moteurs whatsapp-web.js/
Baileys sur lesquels WAHA s'appuie), pas une invention WAHA-spécifique, mais
à confirmer contre la version réellement déployée si les événements observés
divergent.

## Conséquences

- 3 endpoints self-service, sous `project.auth`, préfixe
  `/v1/notification-channel-sessions/{channel}/{sessionRef}` : `GET /qr`,
  `GET /screenshot`, `POST /pairing-code`. Pas de middleware `idempotency`
  (aucun n'est un envoi).
- 1 endpoint webhook entrant, `POST /webhooks/whatsapp-waha`, qui alimente
  `HandleNotificationStatusChangedUseCase` (déjà livré et testé au Lot 5,
  jamais appelé jusqu'ici — désormais en service).
- `config/notification_channels.php` route `whatsapp` vers `whatsapp-waha` ;
  `config/providers.php` porte le bloc `notification.whatsapp_waha`.
- Reste hors périmètre, par choix explicite : toute capacité WAHA au-delà de
  liaison + envoi texte/image/fichier (groupes, présence, contacts,
  webhooks de session, etc.), un `ChannelSessionStatus` canonique persisté
  (délibérément écarté, §3), la vérification HMAC des webhooks entrants.
