# 06 — Portefeuilles, notifications, usage et exports

*Ce chapitre est la référence technique des portefeuilles, des notifications, de l'usage et des exports. Ce que vous cherchez selon l'objet est dans les [parcours F13](../parcours/README.md) (l'argent), [F16](../parcours/README.md) (les messages) et [F18](../parcours/README.md) (les exports).*

Quatre écrans de lecture qui répondent à des questions concrètes : « où est l'argent de ce
client ? », « le message est-il parti ? », « le client a-t-il épuisé son quota ? », « je
redonne les données à mon client ».

---

## 1. Les portefeuilles

Un **portefeuille** (wallet) est un compte de valeur rattaché à un tiers, dans **une seule
devise**. Un tiers qui détient deux devises a deux portefeuilles.

`GET /admin/projects/{projectId}/wallets` — permission `wallets:read`.

> **Cet écran est en lecture seule.** Il n'y a aucun bouton de mutation, et un bandeau
> l'explicite : `« Lecture seule — les wallets ne peuvent jamais être modifiés depuis la
> console admin. »` Seule l'API `/v1/wallets/*` déplace de l'argent entre portefeuilles.

### Les cartes

« Total wallets », puis une carte par devise : `« {CUR} disponible »` et
`« N wallet(s) · {montant} bloqué »`.

### Le tableau

| Colonne | Ce qu'elle contient |
|---|---|
| `ID wallet` | l'identifiant |
| `Tiers` | la référence du tiers |
| `Devise` | `XAF`, `USD`… |
| `Statut` | `active` |
| `Disponible` | le montant spendable |
| `Bloqué` | le montant retenu |
| `« Écritures → »` | le lien vers l'historique |

Recherche : `« Rechercher par tiers (référence ou ID) ou devise… »`. Pagination.

> Les agrégats sont plafonnés à **500 portefeuilles** : au-delà, le total affiché peut être
> inférieur à la réalité.

### L'historique d'un portefeuille

`GET /admin/projects/{projectId}/wallets/{walletId}/ledger` — trois cartes (`Wallet`,
`Disponible`, `Bloqué`) puis le tableau : `Date`, `Type de transaction`, `Référence
externe`, `Sens` (crédit/débit), `Montant` (avec son signe), `ID transaction`.

Un portefeuille appartenant à un autre projet donne `« Wallet introuvable pour ce projet. »`

---

## 2. Les notifications

Une **notification** est un message adressé à un destinataire (par WhatsApp aujourd'hui).
Elle a son propre cycle de vie : `queued` → `sent` → `delivered` → `read`, ou `failed`.

`GET /admin/projects/{projectId}/notifications` — permission `notifications:read`.

**Filtre** : `« Statut »` (`queued`, `sent`, `delivered`, `read`, `failed`).

| Colonne | Ce qu'elle contient |
|---|---|
| `Créée le` | horodatage |
| `ID` | l'identifiant |
| `Canal` | `whatsapp` |
| `Type` | `text`, `image`, `file` |
| `Destinataire` | la référence du destinataire (**jamais** un numéro) |
| `Statut` | badge + le message d'erreur en petit |
| `Provider` | l'adaptateur qui a porté l'envoi |

### Le détail d'une notification

`GET .../notifications/{notificationId}` : `ID`, `Projet`, `Créée le`, `Dernière mise à
jour`, `Destinataire (référence)`, `Provider`, `ID message provider`, `Référence de
l'intégrateur (Idempotency-Key)`, `Erreur`.

Deux blocs JSON : `« Contenu envoyé »` (le message `« Non conservé (notification
antérieure à ADR-0062). »` s'il est vide — le contenu n'est conservé que 90 jours) et
`« Variables »` (les variables envoyées au prestataire).

Le tableau `« Tentatives provider »` : `#`, `Date`, `Provider`, `Statut`, `ID message`,
`Erreur`. **C'est là qu'on voit pourquoi un envoi a échoué.**

### Ce que vous ne verrez jamais

Le contenu d'une notification est conservé 90 jours, puis vidé. Le destinataire est une
**référence**, pas un numéro : le Core ne montre jamais un numéro de téléphone en clair
dans la console.

---

## 3. L'usage et les quotas

`GET /admin/projects/{projectId}/usage` — permission `usage:read`.

Quatre onglets : `« Tous les compteurs »`, `« Au-delà de 80 % »`, `« Limite atteinte »`,
`« Journal »`.

> **Le seuil de 80 % n'est pas une constante du produit** : c'est un paramètre de l'offre
> publiée par le projet. L'onglet « Au-delà de 80 % » liste les compteurs qui ont franchi
> **le seuil que le plan définit**, pas nécessairement 80 %. L'onglet « Limite atteinte »
> liste ceux qui ont atteint 100 %.

Filtre : `« Filtrer par référence client »`.

**Tableau des compteurs** : `Client`, `Compteur`, `Consommation` (jauge), `Renouvellement`.

**Journal** : `Enregistré`, `Client`, `Compteur`, `Quantité`, `Source`, `Clé`
(la clé d'idempotence). C'est la trace de tout ce qui a été mesuré, dans l'ordre.

### Ce que le compteur mesure

Une **quantité** Positive pour un usage, **négative** pour une compensation (un message qui
n'a atteint personne rend l'unité). Chaque mesure porte un `meter` (le compteur) et,
éventuellement, un client.

Les compteurs sont **atomiques** : deux appels simultanés ne peuvent pas dépasser la limite.
Un appel refusé **n'écrit rien** — ni le journal, ni le compteur, ni la clé d'idempotence :
il peut être rejoué plus tard.

### Les seuils déclenchent des événements

Deux événements sont publiés **dans la transaction qui franchit le seuil** :
`usage.threshold_reached` et `usage.limit_reached`. Ils sont donc livrables par webhook.

---

## 4. Les indicateurs du projet

`GET /admin/projects/{projectId}/indicators` — permission `invoices:write`.

Sélecteur de période : **30 / 90 / 365 jours**, 30 par défaut.

| Carte | Ce qu'elle mesure |
|---|---|
| **Revenu récurrent mensuel** | les contrats actifs et impayés |
| **Encaissé ce mois** | ce qui est réellement rentré |
| **Créances** | ce qu'on vous doit |
| **Attrition du mois** | les clients perdus sur le mois |

Trois courbes SVG : revenu récurrent mensuel, encaissé du mois, créances — **par devise**.

Le tableau `« Créances ouvertes par ancienneté et par devise »` : `Devise`, `Non échues`,
`1 à 30 jours`, `31 à 60 jours`, `Plus de 60 jours`.

### La checklist « Ce projet est-il prêt à encaisser ? »

Cinq points, cochés ou non :

1. Une clé d'API a déjà servi
2. Un webhook actif
3. Une offre publiée
4. Des adresses de retour du portail
5. Attribution des usages obligatoire

> **Cette checklist est le meilleur point de départ pour un projet qui démarre.** Un
> projet sans clé utilisée ni webhook actif n'encaisse rien : personne n'appelle.

---

## 5. L'export par projet

Un **export** est une archive `.zip` contenant les données du projet, au format JSON
Lines — c'est-à-dire un fichier texte, une ligne JSON par objet, lisible par n'importe
quel outil.

`GET /admin/projects/{projectId}/exports` — permission `exports:write`.

1. `« Demander un export »`. Le message : `« Export demandé : il est préparé en
   arrière-plan, le lien de téléchargement apparaît ici dès qu'il est prêt. »`
2. Le tableau `« Exports précédents »` : `Demandé le`, `Par`, `Statut`, `Disponible
   jusqu'au`, `« Télécharger »`.

Les statuts : `Prêt`, `Fichier supprimé`, `Échec`, `En cours`, `En attente`.

### Le téléchargement

`« Télécharger »` génère un **lien signé au moment du clic**. Il est **valable 15
minutes** et **à usage unique** : la première requête le consomme, la suivante reçoit `403`.

Si le fichier n'est plus disponible : `« Cet export n'a plus de fichier à télécharger (en
préparation, en échec ou supprimé après 7 jours). »`

> **Les exports expirent au bout de 7 jours.** Le fichier est supprimé ; la ligne reste
> dans la liste pour garder la trace de la demande.

### Ce que contient l'archive

| Fichier | Contenu |
|---|---|
| `customers.jsonl` | les clients du projet |
| `plans.jsonl` | les offres et leurs versions |
| `subscriptions.jsonl` | les abonnements |
| `invoices.jsonl` | les factures |
| `usage.jsonl` | le journal d'usage |
| `payments.jsonl` | les paiements |
| `notifications.jsonl` | les notifications |
| `wallets.jsonl` | les portefeuilles |
| `wallet_transactions.jsonl` | leurs opérations |
| `ledger_transactions.jsonl` | les écritures du grand livre |
| `trial_balance.jsonl` | la balance |
| `manifest.json` | l'index : date, projet, nombre de lignes par fichier |

Chaque ligne est **un objet JSON identique à ce que l'API renvoie**. `ledger_transactions`
et `trial_balance` sont exacts à la date de l'export ; les autres fichiers ont été lus
pendant la préparation.

> L'archive est **chiffrée** au repos sur le disque, et le lien signé est la seule
> authentification : pas de clé d'API nécessaire pour télécharger. Un client effacé reste
> effacé dans l'export.

---

## 6. L'observabilité, quand l'écran ne suffit pas

| Besoin | Outil |
|---|---|
| « Que s'est-il passé à l'instant T ? » | le **journal d'audit** (chapitre 01) |
| « Pourquoi ce paiement a-t-il échoué ? » | la **chronologie** de la fiche paiement (chapitre 05) |
| « Pourquoi cet envoi WhatsApp a-t-il échoué ? » | les **tentatives provider** de la notification |
| « La plateforme est-elle debout ? » | `GET /ready` |

- `GET /up` : **vivacité**. Le processus répond.
- `GET /ready` : **disponibilité réelle**. Base de données, cache Valkey, et relais de
  l'outbox effectué depuis moins de 5 minutes. Renvoie `503` si un contrôle échoue, sans
  jamais exposer de message d'erreur.
- `GET /health/ready` : la même question posée sur le plan API. Elle vérifie la base et le
  cache, mais **pas** le relais de l'outbox. **Interrogez `GET /ready`** — c'est elle que la
  sonde de supervision et l'ordonnanceur utilisent.
- `GET /metrics` : format **Prometheus**. Accès par réseau privé, ou par
  `Authorization: Bearer <METRICS_TOKEN>`.

Les files de traitement sont visibles dans **Horizon** (`/horizon`), réservé aux
administrateurs de plateforme (permission `queues:manage`). C'est là qu'on voit une file
qui grossit. Quatre files portent les traitements lents : `default`, `notifications`,
`payment-callbacks`, `webhooks`.

Une alerte « File Horizon en retard » se déclenche au-delà de **500 jobs pendant 10
minutes**. Une alerte « Relais outbox bloqué » se déclenche quand un événement n'a pas été
relayé depuis plus de **5 minutes**.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Portefeuilles (lecture) | `app/Http/Controllers/Admin/WalletsController.php` |
| Notifications | `app/Http/Controllers/Admin/NotificationsController.php` |
| Usage et quotas | `app/Http/Controllers/Admin/ProjectCommerceController.php` (`usage`) |
| Indicateurs | `app/Http/Controllers/Admin/ProjectIndicatorsController.php` |
| Exports | `app/Http/Controllers/Admin/ProjectExportsController.php`, `packages/export/` |
| Le compteur atomique | `packages/billing/src/Infrastructure/Persistence/Usage/PostgresUsageStore.php` |
| Sondes et métriques | `routes/api.php` (`/health/ready`), `docs/operations/observabilite.md` |
| Files | `config/horizon.php` |
