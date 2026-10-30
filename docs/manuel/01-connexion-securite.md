# 01 — Connexion et sécurité

Ce chapitre couvre la porte d'entrée de la console : votre compte, le code à 6 chiffres, les
actions qui en redemandent un second, et le journal qui garde la trace de tout.

---

## 1. Créer son compte : l'invitation

Un compte opérateur **n'est jamais créé avec un mot de passe choisi par le Core**. Il
s'ouvre par une **invitation** : un jeton d'activation, valable **72 heures**, à usage
unique.

1. L'administrateur de la plateforme vous envoie le jeton. Il vient soit de l'écran
   `« Opérateurs »` (rôle de plateforme), soit de l'écran
   `« Membres »` d'une organisation.
2. Vous ouvrez `GET /admin/invitation` et collez le jeton dans le champ
   `« Jeton d'activation »`.
3. Vous choisissez un mot de passe (et sa confirmation), puis vous validez.
4. Le compte est activé. Si le mot de passe est trop faible, l'écran le dit et refuse.

Trois refus possibles, tous affichés en clair :

| Message | Cause |
|---|---|
| invitation inconnue / expirée / révoquée / déjà utilisée | le jeton a servi, ou les 72 heures sont passées |
| « Vous avez déjà un compte : saisissez son mot de passe actuel, qui n'est pas remplacé. » | l'invitation vise un compte existant ; votre mot de passe ne change pas |
| « Vous êtes déjà membre de cette organisation. » | l'invitation est un doublon |

> Le jeton d'activation est un **secret**. Ne le collez jamais dans un canal partagé, un
> ticket, ou une capture d'écran.

### Inviter un opérateur (écran `« Opérateurs »`)

Réservé à la **plateforme** — un membre d'organisation ne voit même pas l'entrée de menu.

1. `GET /admin/operators/create` — formulaire `« Inviter un opérateur »`.
2. Renseignez `email` (obligatoire) et `role` (obligatoire, liste déroulante).
3. `« Envoyer l'invitation »`.
4. L'écran affiche **dans la même page** un encart vert `« Invitation créée : {email} »`
   avec le jeton à usage unique et sa date d'expiration. **Copiez-le immédiatement** : il
   ne sera plus jamais affiché.

Un e-mail déjà pris, une invitation déjà existante ou un rôle inconnu font échouer la
saisie, avec le nom du champ fautif.

### Modifier et révoquer un opérateur

- `GET /admin/operators/{operatorId}/edit` — `email`, `name` (obligatoires), `role`.
  Bouton `« Enregistrer les modifications »`.
- `POST /admin/operators/{operatorId}/revoke` — révocation immédiate. Un **motif n'est pas
  demandé** ici, contrairement aux clés d'API.
- Deux refus vous protègent : vous ne pouvez pas **révoquer votre propre compte** («
  demandez à un autre administrateur »), et la console **garde toujours un administrateur de
  plateforme actif**.

---

## 2. Se connecter : le mot de passe puis le code

`GET /admin/login` — bouton `« Continuer »`.

1. Saisissez votre `email` et votre `password`.
2. Le mot de passe est vérifié. S'il est faux : `« Identifiants invalides. »` — le même
   message que si l'adresse n'existe pas, pour ne pas révéler quels comptes sont ouverts.
3. Le Core ne vous ouvre pas encore la console : il vous envoie vers le **défi MFA**.

### La première fois : configurer l'authentificateur

`GET /admin/mfa/enroll` — c'est le cas de tout nouvel compte.

1. Scannez le **QR code** affiché à l'écran avec votre application d'authentification
   (Google Authenticator, Authy, 1Password…). Le QR code est rendu par le serveur, en SVG :
   aucun service tiers n'est appelé.
2. Si vous ne pouvez pas scanner, recopiez le **secret** affiché à côté, à la main.
3. Saisissez le **code à 6 chiffres** que votre application vient d'afficher.
4. `« MFA configurée. »` — vous êtes connecté.

> Le secret en clair est l'équivalent de votre clé : quiconque le possède peut se connecter
> à votre place. Ne l'envoyez pas par messagerie.

### Les fois suivantes : vérifier le code

`GET /admin/mfa/verify` — un seul champ : le code à 6 chiffres.

- `« Code invalide ou MFA non configurée. »` : le code est faux ou expiré.
- `« Bienvenue. »` : vous êtes connecté.

Le code est **à usage unique** et expire vite. Si vous avez raté la fenêtre, attendez que
l'application affiche le code suivant.

### La session

- Le code de la connexion **compte aussi** comme une identité confirmée : vous n'avez pas à
  le ressaisir juste après.
- La session se ferme toute seule après une période d'inactivité ; vous repassez par le
  code.
- `POST /admin/logout` (`« Se déconnecter », en bas de la barre latérale) révoque le jeton
  et vide la session. Le message `« Déconnecté. »` confirme.

### Limites anti-bruit

Les tentatives de connexion sont plafonnées : **20 par minute et par adresse IP**, et
**5 par minute pour un même couple e-mail + mot de passe**. Au-delà, la requête est
refusée. L'activation d'invitation est plafonnée de la même façon (10/min par IP, 5/min
par jeton).

> Sur un environnement de démonstration où le MFA est désactivé, la connexion ne demande
> qu'un mot de passe. Le journal d'audit enregistre alors `operator.mfa_skipped` à chaque
> connexion. **Ce n'est pas le cas en production.**

---

## 3. L'élévation : le second code, réservé à l'argent

Il y a deux niveaux de preuve, et ils ne se ressemblent pas.

| | MFA de connexion | Élévation (« step-up ») |
|---|---|---|
| Quand | à chaque connexion | juste avant une action précise |
| Durée | la session | **5 minutes** |
| Écran | `« Vérification MFA »` | `« Confirmez votre identité »` |

**L'élévation n'est demandée que pour les actions qui déplacent de l'argent.** Ce sont
exactement sept, et toutes sont dans le chapitre 05 :

| Action | Écran |
|---|---|
| Émettre une facture | fiche d'une facture → `« Émettre »` |
| Annuler une facture | fiche d'une facture → `« Annuler la facture »` |
| Déclarer une facture irrécouvrable | fiche d'une facture → `« Passer en irrécouvrable »` |
| Valider un encaissement hors ligne | `« Encaissements hors ligne »` → `« Valider »` |
| Résoudre une exception d'argent | fiche d'une exception → `« Enregistrer la décision »` |
| Approuver une exception d'argent | fiche d'une exception → `« Valider et écrire au grand livre »` |
| Approuver une correction du grand livre | fiche d'une correction → `« Valider et écrire au grand livre »` |

**Tout le reste n'en demande pas.** Les écritures sensibles qui ne déplacent pas d'argent —
créer une clé d'API, enregistrer un endpoint webhook, inviter un opérateur, changer un rôle
d'organisation, effacer un client, refuser une exception — passent sans second code. Elles
restent **journalisées dans le journal d'audit** et **plafonnées** (20 gestes par minute et
par opérateur, 60 par minute et par adresse IP).

### Ce qui se passe concrètement

1. Vous cliquez sur une action d'argent.
2. La console vous renvoie vers `GET /admin/step-up` — l'écran `« Confirmez votre
   identité »`, qui rappelle que le code reste valable 5 minutes.
3. Vous saisissez le code à 6 chiffres.
4. `« Identité confirmée pendant 5 minutes. Vous pouvez recommencer l'action. »`
5. Vous revenez sur l'écran d'origine, l'action est refaite, elle passe.

Si le code est faux : `« Code invalide, expiré ou déjà utilisé. Attendez le prochain code de
votre application. »` Si votre compte a été révoqué entre-temps : vous êtes renvoyé vers la
connexion avec `« Votre accès opérateur n'est plus valide. »`

La preuve est **liée à votre session** : ouvrir l'écran d'élévation dans une autre
navigateur ne vous donne rien.

---

## 4. Les rôles : ce que chacun voit

Il y a **deux familles de rôles**, et elles ne se mélangent pas.

### Rôles de plateforme

| Rôle | Peut |
|---|---|
| **Administrateur de plateforme** | Tout. C'est le seul rôle qui porte `operators:read` / `operators:write`, `queues:manage`, la création d'organisations, et l'archivage d'un projet. |
| **Opérateur financier** | Paiements, portefeuilles, exceptions d'argent, corrections, réconciliation, audit. **Ne voit pas** la facturation (ni clients, ni offres, ni factures) ni le portefeuille transverse. |
| **Opérateur support** | Webhooks (et rejeu), endpoints webhook, notifications, paiements en lecture, audit. **Ne gère pas** l'argent. |
| **Responsable des prestataires** | Applications de prestataires de paiement, sessions WhatsApp. **Ne voit pas** le menu « Paiements ». |
| **Auditeur (lecture seule)** | Lit tout ce qui se lit : audit, projets, paiements, portefeuilles, notifications, webhooks, exceptions. N'écrit rien. |

### Rôles dans une organisation

| Rôle | Peut |
|---|---|
| `org_owner` | Tout dans son organisation. Seul rôle qui peut **attribuer** `org_owner` et **archiver** un projet. |
| `org_admin` | Projets, clés d'API, webhooks, prestataires, sessions WhatsApp, clients, offres, abonnements, factures, quotas, portail, exports, membres. |
| `org_finance` | Clients, abonnements, factures, paiements, réconciliation, portefeuilles, exceptions d'argent. |
| `org_support` | Lit clients, abonnements, factures ; agit sur les abonnements ; lit et rejoue les webhooks. |
| `org_viewer` | Lit tout, n'écrit rien. |

### Comment le produit applique ces rôles

1. **Un projet hors de votre périmètre renvoie « introuvable », pas « interdit ».** Vous ne
   pouvez pas savoir qu'il existe. C'est une règle générale : tous les écrans de la
   console.
2. **Un projet visible mais interdit renvoie `403`** avec le nom de la permission
   manquante : `« La permission invoices:write est requise sur ce projet. »`
3. **Un écran absent du menu est absent.** Un opérateur support ne voit pas « Wallets »
   dans la barre latérale ; il n'y entre pas par une URL devinée.
4. **Le pied de la barre latérale affiche votre rôle** en clair :
   `« Rôle : Opérateur financier »`. Si vous n'avez pas de rôle de plateforme, il affiche
   `« Membre d'organisation »`.

### Inviter un membre d'organisation

`GET /admin/organizations/{organizationId}/members/invite` — `email` et `role` (parmi les
cinq rôles `org_*`).

- Seul un `org_owner` peut attribuer `org_owner`. Tenter le contraire :
  `« Vous ne pouvez pas attribuer un rôle supérieur au vôtre : seul un propriétaire attribue
  le rôle de propriétaire. »`
- Une organisation garde **toujours un propriétaire**. Le retirer ou le rétrograder est
  refusé : `« Une organisation garde toujours un propriétaire : nommez-en un autre avant de
  retirer ou de rétrograder celui-ci. »`
- Le jeton d'invitation est affiché une seule fois, dans la réponse.

Depuis l'écran `« Membres »`, vous pouvez aussi **changer un rôle** (sélecteur puis
`« Appliquer »` — le changement ne s'applique pas à la volée) et **révoquer** une
appartenance.

---

## 5. Le journal d'audit

`GET /admin/audit-log`. Il enregistre **qui** a fait **quoi**, sur **quelle ressource**, et
**quand**. C'est la première chose à regarder quand un client conteste un geste.

### Ce qu'il contient

Chaque ligne porte :

| Colonne | Ce que c'est |
|---|---|
| `Quand` | horodatage |
| `Acteur` | qui a agi : `operator:…`, `api_key:…`, `service:…` |
| `Action` | le code du geste : `api_key.issued`, `portal.session_opened`, `billing.settings_changed`… |
| `Ressource` | sur quoi : `project:…`, `invoice:…`, `operator:…` |
| `Corrélation` | l'identifiant de corrélation, qui relie le geste aux journaux techniques et aux traces |
| `Détails` | un bouton `« Voir ▸ »` qui déplie `Avant`, `Après`, `Contexte` en JSON formaté |

Le bouton `« Voir ▸ »` disparaît quand il n'y a rien à montrer.

### Les filtres

Cinq filtres, tous combinables :

| Champ | Ce qu'il attend |
|---|---|
| `« Ressource »` | l'identifiant de la ressource |
| `« Acteur »` | l'identifiant brut, par exemple `operator:…` |
| `« Action »` | le code brut, par exemple `api_key.issued` |
| `« Depuis le »` | une date |
| `« Jusqu'au »` | une date |

`« Filtrer »` applique, `« Réinitialiser »` remet à zéro. Pagination par `page` /
`per_page` (25 par page, 100 au maximum).

### Ce que le journal garantit

- Il est **append-only** : ni mise à jour ni suppression ne sont possibles.
- Il est **scopé** : un opérateur d'organisation ne voit que les lignes de son
  organisation.
- Il est **chaîné par empreintes** quotidiennes : altérer une ligne casse la chaîne et se
  voit au contrôle.
- Les **secrets n'y sont jamais** : ni mot de passe, ni clé d'API, ni secret webhook, ni
  donnée personnelle en clair.

---

## 6. Les quotas de débit de la console

Ils ne sont pas visibles dans l'interface, mais ils expliquent un refus que vous ne
comprenez pas.

| Plan | Ce qu'il protège | Limite |
|---|---|---|
| `operator-auth` | connexion et MFA | 20/min par IP, **5/min par couple e-mail + mot de passe** |
| `invitation-accept` | activation d'invitation | 10/min par IP, 5/min par jeton |
| `console-sensitive` | toutes les écritures sensibles | **20/min par opérateur**, 60/min par IP |

Une action refusée par ce plafond affiche un message de trop de tentatives. Ce n'est pas un
bug : attendez une minute.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Connexion, MFA, enrôlement, session | `app/Http/Controllers/Admin/AuthController.php` |
| Écran d'élévation et fenêtre de 5 minutes | `packages/identity/src/Presentation/Http/Middleware/StepUpGate.php` |
| Interception avant une action d'argent | `packages/identity/src/Presentation/Http/Middleware/RequireOperatorStepUp.php` |
| Rôles de plateforme | `packages/identity/src/OperatorRole.php` |
| Rôles d'organisation | `packages/identity/src/Domain/Model/OrganizationRole.php` |
| Journal d'audit | `packages/audit/` (port `AuditQuery`), écran `app/Http/Controllers/Admin/AuditLogController.php` |
| Limites de débit | `app/Providers/AppServiceProvider.php`, `packages/admin/src/AdminServiceProvider.php` |
| Liste des routes et de leurs garde-fous | `routes/admin.php` |
