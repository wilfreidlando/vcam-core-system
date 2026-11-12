# 03 — Clés d'API, webhooks, prestataires et sessions WhatsApp

*Ce chapitre est la référence technique des clés d'API, des webhooks, des applications de prestataire et des sessions WhatsApp. La procédure est dans les [parcours F14 à F16](../parcours/README.md).*

Ce chapitre couvre les quatre objets qui branchent un produit extérieur sur le Core : la
**clé d'API**, l'**endpoint webhook**, l'**application de prestataire** et la **session
WhatsApp**.

---

## 1. Les clés d'API

Une **clé d'API** est le jeton qui prouve au Core qui appelle. Elle est **nominative** :
un projet en a plusieurs, chacun pour un usage différent (le serveur, le portail, une
opération de versement).

`GET /admin/projects/{projectId}/api-keys/create` — permission `api-keys:write`.

### Les deux notions à ne pas confondre

| Notion | Ce que c'est | Combien |
|---|---|---|
| **Portée** (*scope*) | ce que la clé a le droit de faire | 25 portées nommées |
| **Modèle** | un jeu de portées prêt à l'emploi | 5 modèles |

Les cinq modèles proposés par l'écran :

| Modèle | Portées |
|---|---|
| **Serveur complet** | tout sauf `payouts:write` |
| **Lecture seule** | toutes les portées `:read` |
| **Portail uniquement** | `portal:write` + `customers:read` |
| **Application cliente** | `customers:read` + `usage:read` + `usage:write` + `portal:write` + `subscriptions:read` + `invoices:read` + `notifications:write` + `notifications:read` + `tenant_schema:write` |
| **Versements** | `payouts:read` + `payouts:write` |

Le modèle **Application cliente** est celui d'un logiciel qui se branche au Core
(ADR-0107). Il lui permet de **lire ses droits**, de **compter son usage**, d'**ouvrir
le portail** de son propriétaire, de **lire** son état commercial et de **déclarer la
forme de ses clients** (`tenant_schema:write` — ADR-0122 §8 : ce n'est pas une
écriture sur le commerce, c'est une *forme*). Il ne lui donne
**aucune** écriture sur le commerce : ni `customers:write`, ni `subscriptions:write`,
ni `invoices:write`. Le commerce se fait dans le portail, par le propriétaire.

**Personnalisée** affiche la liste des 25 portées en cases à cocher. Une clé qui n'a pas la
portée demandée reçoit `403`.

### Créer une clé

1. `« Nom »` (obligatoire, 80 caractères) — un nom qui dit à quoi elle sert :
   `« Serveur de facturation »`.
2. Choisissez un **modèle**, ou `« Personnalisée »` et cochez les portées.
3. `« Restriction réseau »` (facultatif) — **une adresse par ligne** : un bloc IPv4
   (`203.0.113.0/24`) ou une adresse IPv6 (`2001:db8::/32`). Une fois renseignée, tout
   appel venant d'une autre adresse est refusé (`403`). **C'est la protection la plus
   efficace si votre application tourne en hébergement fixe.**
4. `« Expiration (heure UTC) »` (facultatif). Une clé expirée est refusée comme une clé
   inconnue (`401`).
5. `« Créer la clé »`.

**La clé s'affiche une seule fois**, dans l'encart vert `« Nouvelle clé : {prefix} »`. Elle
ressemble à `pk_XXXXXXXX.XXXXXXXXXXXXXXXX`. Le préfixe est visible dans les journaux ; le
secret ne l'est jamais.

> **Le maximum est de 10 clés actives par projet.** Au eleventh, la création est refusée.
> Comptez une clé de plus pendant une rotation.

### Faire tourner une clé

`« Faire tourner »` — un champ `« Ancienne clé valide encore (jours) »`.

1. Saisissez le nombre de jours de grâce (entre 1 et 30, 7 par défaut).
2. La nouvelle clé s'affiche **une seule fois**.
3. **L'ancienne continue de fonctionner** pendant la période de grâce. Déployez la
   nouvelle, vérifiez qu'elle est utilisée, puis laissez l'ancienne expirer.

> Une rotation n'annule pas la révocation : la nouvelle clé a **les mêmes portées et le
> même réseau** que l'ancienne. Si vous voulez changer de périmètre, créez une clé
> neuve et révoquez l'ancienne.

### Révoquer une clé

`« Révoquer »` — un champ `« Motif (journalisé) »`, 3 à 500 caractères. **Le motif est
obligatoire** : il est écrit dans le journal d'audit, c'est votre trace en cas d'incident.
La révocation est immédiate : toute application qui utilise la clé est rejetée dès la
requête suivante.

### Le tableau des clés

`Nom`, `Préfixe`, et une pastille d'état :

| Pastille | Ce qu'elle veut dire |
|---|---|
| `Active` | pas de date d'expiration |
| `Active · expire le …` | une expiration est posée |
| `Remplacée · valide jusqu'au …` | elle a été tournée, l'ancienne est encore valable |
| `Expirée` | elle ne fonctionne plus |

S'y ajoutent `Portées` (le libellé du modèle, ou `Personnalisée · N portée(s)`),
`Réseau` (`« toute adresse »` ou la liste des blocs), `Créée le`, et
`« Dernière utilisation »` — avec l'adresse IP, ou `« jamais »`.

---

## 2. Les endpoints webhook

Un **endpoint webhook** est une URL du projet que le Core appelle pour annoncer un
événement. Chaque livraison est **signée** : votre récepteur vérifie la signature avant
d'accepter.

`GET /admin/projects/{projectId}/webhook-endpoints/create` — permission
`webhook-endpoints:write`.

### Ce que vous choisissez

Vous ne saisissez jamais un type d'événement brut. Vous cochez des **catégories** :

| Catégorie | Événements reçus |
|---|---|
| `Paiements` | `payment.status_changed` |
| `Wallets` | `wallet.credited`, `wallet.debited` |
| `Notifications` | `notification.delivered`, `notification.read`, `notification.failed` |
| `Abonnements` | 12 types (création, activation, renouvellement, essai qui finit, échéance, changement d'offre, impayé, pause, reprise, annulation, expiration) |
| `Factures` | `invoice.issued`, `invoice.paid`, `invoice.payment_failed`, `invoice.voided` |
| `Quotas` | `usage.threshold_reached`, `usage.limit_reached` |

Plus la case `« Tout recevoir »`, qui demande `*`.

> **« Tout recevoir » mélange des types très différents sur la même URL.** Pour un client
> qui ne fait qu'un usage, choisissez la catégorie. Le lien `« Quels événements vais-je
> recevoir ? → »` (`/admin/webhook-events`) est la page de référence du format de livraison
> et des principales familles d'événements, avec un exemple pour chacune.

### Enregistrer un endpoint

1. `« URL de réception »` — obligatoire, doit commencer par `https://` ou `http://`.
   **Les adresses internes sont refusées** (localhost, plages privées, link-local) : un
   endpoint est retenté automatiquement pendant des heures, le Core ne doit donc pas
   pouvoir être utilisé pour sonder votre réseau interne.
2. Cochez au moins une catégorie, ou `« Tout recevoir »`. Sinon : `« Choisissez au moins
   une catégorie, ou "Tout recevoir". »`
3. `« Enregistrer l'endpoint »`.
4. Le **secret de signature** s'affiche une seule fois, dans l'encart vert
   `« Endpoint enregistré : {url} »`. Il ressemble à `whsec_…`.

**Maximum 10 endpoints actifs par projet.** Au-delà :
`« Ce projet atteint déjà son plafond de {N} endpoints webhook actifs. Désactivez-en un
avant d'en enregistrer un autre. »`

### Activer, désactiver, modifier

- `« Modifier »` : un formulaire replié avec l'URL et les catégories.
- `« Désactiver »` / `« Réactiver »` : un endpoint désactivé ne reçoit plus rien. Les
  livraisons déjà planifiées **continuent**.
- `« Faire tourner le secret »` : champ `« Recouvrement (heures) »`, de 1 à 72, 24 par
  défaut.

### La rotation du secret et la double signature

C'est le point le plus subtil de ce chapitre.

1. Vous demandez une rotation avec un recouvrement de 24 heures.
2. Un **nouveau secret** s'affiche une seule fois.
3. **Pendant toute la fenêtre**, chaque livraison porte **deux** signatures :
   - `X-Signature`, calculée avec le **nouveau** secret ;
   - `X-Signature-Previous`, calculée avec l'**ancien** secret.

Votre récepteur doit **accepter l'une ou l'autre**. Si vous n'acceptez que la première,
vous coupez les livraisons le jour de la bascule ; si vous n'acceptez que la seconde, de
même.

4. Passé la fenêtre, l'ancien secret est effacé et `X-Signature-Previous` disparaît.
5. `« Révoquer l'ancien secret »` permet d-accélérer : un champ `« Motif (journalisé) »`
   (3 à 500 caractères) est demandé. Après, **seules les signatures du nouveau secret sont
   envoyées**.

> **Si votre récepteur n'accepte pas les deux signatures, ne utilisez pas la rotation.**
> Créez un endpoint neuf, basculez dessus, puis désactivez l'ancien. C'est plus long,
> mais c'est sans risque de perte.

### Ce que reçoit votre serveur

En-têtes :

| En-tête | Contenu |
|---|---|
| `X-Event-Id` | l'identifiant de l'événement |
| `X-Event-Type` | le type, par exemple `payment.status_changed` |
| `X-Delivery-Id` | l'identifiant de la livraison |
| `X-Timestamp` | l'heure en secondes Unix |
| `X-Signature` | la signature |

Le corps est l'enveloppe `{id, type, data}`.

La signature est :

```
X-Signature = HMAC-SHA256(secret, "{X-Timestamp}.{corps brut}")
```

**Le corps se signe avant d'être décodé.** Refusez une livraison dont le `X-Timestamp`
s'écarte de plus de **300 secondes** de votre heure, et comparez les signatures en temps
constant.

Le format exact, l'algorithme et les exemples de vérification sont dans
[`docs/integration/webhooks.md`](../../integration/webhooks.md), avec un vérificateur en PHP,
en Node et en Python.

### Les livraisons qui échouent

Le Core retente **8 fois**, avec un délai de base de 30 secondes, un multiplicateur de ×3
et un plafond de 6 heures. Un `4xx` (sauf `408` et `429`) est un échec **définitif** : pas
de nouvelle tentative, la livraison passe en file des lettres mortes.

Voir le chapitre 05 pour le rejeu depuis la console.

---

## 3. Les applications de prestataire de paiement

Chaque **projet** dispose de sa propre application chez le prestataire. Aucune clé partagée.

`GET /admin/projects/{projectId}/provider-applications/create` — permission
`provider-applications:write`.

1. `« Provider »` — liste déroulante alimentée par les **adaptateurs réellement
   enregistrés** dans le Core. Ce n'est pas du texte libre : un code que le Core ne
   connaît pas est refusé. Si la liste est vide : `« Aucun adaptateur de provider de
   paiement n'est enregistré dans ce Core. »`
2. `« Libellé »` (facultatif, 120 caractères) — `« Boutique principale »`.
3. `« Clé publique »` (obligatoire).
4. `« Clé privée »` — **obligatoire à la création**, facultative en modification. Laissez
   vide pour conserver la clé actuelle : `« Laissez vide pour conserver la clé privée
   actuelle (elle n'est jamais réaffichée). »`

`« Enregistrer l'application »`. L'audit enregistre `provider_application.registered` (ou
`provider_application.rotated` si l'application existait déjà).

**Suppression** : `« Supprimer »` → `« Application supprimée. Les paiements de cet
environnement ne passeront plus par ce provider tant qu'une autre application n'est pas
enregistrée. »`

> Une application de prestataire est un **secret de production**. Elle n'apparaît jamais
> dans les journaux en clair et n'est jamais réaffichée après enregistrement.

---

## 4. Les sessions WhatsApp

Une **session WhatsApp** est la liaison entre un téléphone et un nom. C'est ce nom que le
projet passe dans `variables.session` quand il envoie une notification.

**Il n'existe aucune session par défaut.** Tant qu'aucune session n'est enregistrée, tout
envoi WhatsApp est refusé : `422 channel-session-not-found`.

### Sessions de projet et sessions globales

| | Sessions de projet | Sessions globales |
|---|---|---|
| URL | `/admin/projects/{projectId}/whatsapp-sessions` | `/admin/whatsapp-sessions` |
| Qui peut | `channel-sessions:write` dans l'organisation | **plateforme uniquement** |
| Utilisables par | ce projet | **tous** les projets |

### Créer une session

`GET .../whatsapp-sessions` → formulaire `« Créer une session »` :

- `session_ref` : le nom de la session. Lettres, chiffres, `-` et `_`, 64 caractères
  maximum. **C'est ce nom que le client passe dans `variables.session`.** Choisissez-le
  lisiblement : `« boutique-orange-ci »`.
- `« Créer et démarrer »`.

La session est créée **chez le prestataire et dans le Core**. Elle n'est pas encore liée à
un téléphone.

### Lier un téléphone

Trois méthodes, sur la page de liste de sessions :

| Action | Ce qu'elle fait |
|---|---|
| `« Scanner (QR) »` | ouvre le QR code à scanner avec WhatsApp |
| `« Code d'appairage »` | un champ `phone_number` → le code s'affiche : `« Code d'appairage pour "{ref}" : {code} — saisissez-le dans WhatsApp › Appareils connectés › Associer avec le numéro de téléphone. »` |
| `« Capture d'écran »` | l'état du téléphone tel que le prestataire le voit |

`« Statut »` interroge le prestataire et renvoie un message lisible :

| Message | Signification |
|---|---|
| « un téléphone est lié, la session peut envoyer. » | tout va bien |
| « démarrée, en attente de liaison — scannez le QR code ou demandez un code d'appairage. » | il faut lier un téléphone |
| « arrêtée chez le fournisseur : aucun envoi possible tant qu'elle n'est pas redémarrée. » | relancez la session |
| « en échec chez le fournisseur : supprimez-la puis recréez-la. » | à recréer |
| « enregistrée dans le Core mais inconnue du fournisseur : supprimez-la puis recréez-la. » | désynchronisation |
| « état non reconnu renvoyé par le fournisseur. » |/provider à vérifier |

### Supprimer une session

`« Supprimer »` → `« Session "{ref}" supprimée (chez le fournisseur et dans le Core) : plus
aucun envoi possible avec elle. »`

La suppression est définitive des deux côtés. Pour une session globale, le message de
confirmation est renforcé.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Clés d'API (console) | `app/Http/Controllers/Admin/ApiKeysController.php` |
| Portées et modèles | `packages/identity/src/Contracts/ApiKeyScopes.php` |
| Endpoints webhook (console) | `app/Http/Controllers/Admin/WebhookEndpointsController.php` |
| Livraison signée et double signature | `packages/webhook/src/Infrastructure/Delivery/HttpWebhookSender.php` |
| Politique de retry | `packages/webhook/src/Domain/Model/WebhookRetryPolicy.php` |
| Catalogue des types d'événements | `packages/webhook/src/Contracts/WebhookEventTypes.php` |
| Applications de prestataire | `app/Http/Controllers/Admin/ProviderApplicationsController.php` |
| Sessions WhatsApp | `app/Http/Controllers/Admin/ChannelSessionsController.php` |
| Vérificateurs de signature | `docs/integration/webhooks.md`, `docs/integration/examples/verify-signature.{php,js,py}` |
| Règles de portée et de rotation | `docs/adr/0082-cles-d-api-portees-rotation-et-secrets-de-webhook.md` |
