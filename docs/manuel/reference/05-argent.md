# 05 — Argent

*Ce chapitre est la référence technique des paiements, de la réconciliation, des exceptions et des corrections d'exploitation. La procédure est dans les [parcours F09 à F12](../parcours/README.md) ; la confirmation des actes qui déplacent de l'argent, dans le [parcours F02](../parcours/README.md).*

Paiements, réconciliation, exceptions d'argent, corrections d'exploitation. Ce sont les
écrans où l'on décide si de l'argent bouge — et, quand il bouge, on en laisse une trace
incompressible.

---

## 1. Les paiements

`GET /admin/payments` — permission `payments:read`.

**Filtres** : `« Projet »` (tous les projets par défaut), `« Canal »`, `« Statut »`.
Boutons `« Filtrer »` / `« Réinitialiser »`.

| Colonne | Ce qu'elle contient |
|---|---|
| `Créé le` | horodatage |
| `ID` | l'identifiant du paiement |
| `Projet` | le nom résolu |
| `Canal` | `mtn_momo`, `orange_money`, `card`… |
| `Statut` | `pending`, `processing`, `succeeded`, `failed`, `expired`, `refunded`, `canceled` |
| `Montant` + `Devise` | en unités mineures |

> **Attention au filtre « Canal ».** Il filtre **côté navigateur**, après la pagination
> serveur : le total affiché ne correspond pas au filtre. Utilisez-le pour balayer une page,
> pas pour compter.

### La fiche d'un paiement

`GET /admin/payments/{payment}?project_id=…` — le `project_id` est **obligatoire** ; sans
lui, vous revenez à la liste avec `« Un project_id est requis pour rechercher un
paiement. »`

Champs : `ID`, `Projet`, `Créé le`, `Dernière mise à jour`, `Canal`, `Provider`,
`Référence provider`, `Référence de l'intégrateur (Idempotency-Key)`, `Action requise`
(oui/non), `Indication d'action`, `URL de paiement`, `URL de retour (navigateur)`, `URL de
callback`, `Wallet à créditer`.

Trois encadrés à lire avec attention :

| Encadré | Ce qu'il dit |
|---|---|
| **URL de paiement** | un lien cliquable **seulement en https**. En http, le texte est affiché avec `« Adresse non cliquable : seul le schéma https est accepté comme lien. »` |
| **URL de callback** | `« Notification directe à cette URL — pas de relais vers les endpoints webhook du projet pour ce paiement. »` |
| **Wallet à créditer** | `« — (encaissement pur, aucun wallet) »` quand le projet n'a demandé aucun crédit. C'est le cas normal. |

### Le parcours d'un paiement

Sous la fiche, une **chronologie** qui raconte l'histoire du paiement. Les étapes possibles
sont : `Requête reçue`, `Appel provider`, `Paiement créé`, `Requête rejouée`, `Réponse
envoyée`, `Callback provider`, `Callback rejeté`, `Réconciliation`, `Changement de statut`,
`Statut ignoré`, `Wallet crédité`, `Écrit au grand livre`, `Écart de montant`, `Événement
publié`, `Callback URL`.

**`Statut ignoré` est l'étape à comprendre.** Elle signifie qu'un callback est arrivé avec
un statut qui ne fait plus avancer le paiement (un `succeeded` sur un paiement déjà
`canceled`, par exemple). Le callback est **journalisé mais refusé** : un callback tardif ne
peut pas faire reculer un paiement réglé.

**`Écart de montant`** signifie que le prestataire a confirmé un autre montant ou une
autre devise. L'argent est bien chez le prestataire, mais il n'est **ni crédité à un wallet,
ni imputé à une facture**. Il part en exception d'argent (section 4).

---

## 2. La réconciliation

Un **réconciliation** interroge le prestataire pour savoir ce qu'est réellement arrivé au
paiement, et met le statut à jour comme si le webhook était arrivé. Elle rattrape les
webhooks perdus.

`GET /admin/reconciliation/new` — permission `payments:reconcile:write`.

Le bandeau en haut explique l'action : `« Que fait concrètement une réconciliation ? »
(appel provider, mise à jour comme si le webhook était arrivé, crédit wallet inclus)`.

### Prévisualiser avant de lancer

Le tableau `« Paiements à réconcilier »` liste `ID`, `Projet`, `Canal`, `Statut`, `Montant`,
`Bloqué depuis` (en clair : « 12 min », « 2 h 15 »), `Action` avec un bouton
`« Réconcilier »` par ligne.

Si aucun paiement n'est bloqué : `« Aucun paiement n'a besoin de réconciliation pour le
moment »`, et le bouton groupé est désactivé (`« Indisponible : aucun paiement bloqué à
vérifier. »`).

### Lancer

- `« Réconcilier »` sur une ligne : `POST /admin/reconciliation/{payment}`. Un paiement
  introuvable → `« Paiement introuvable. »`. Une panne ou un prestataire non enregistré →
  `« Échec de la vérification auprès du provider : … »`.
- `« Lancer la réconciliation groupée maintenant »` : `« Nombre de lignes (max 20) »`, 1 à
  20. Le résultat est affiché en trois chiffres : `Vérifiés`, `Mis à jour`, `Erreurs`.

> Une réconciliation **constate**, elle n'écrit jamais une correction à la main. Si un
> écart apparaît, c'est une **exception d'argent** qui s'ouvre, pas un ajustement
> silencieux.

---

## 3. Les livraisons de webhooks

`GET /admin/webhook-deliveries/dead` — permission `webhooks:read`.

> **Le nom de l'URL vous trompe.** `/admin/webhook-deliveries/dead` affiche **tout
> l'historique** des livraisons, pas seulement les échecs. Le titre de la page est
> `« Livraisons de webhooks »` et un filtre `« Statut »` permet de choisir : `Tous`,
> `« En attente »`, `« Livré »`, `« En échec (mort) »`.

Colonnes : `ID`, `URL du endpoint`, `Projet`, `Statut`, `Type d'événement`, `Tentatives`,
`Dernière erreur`, `Dernière tentative`, `Action`.

Le bouton `« Rejouer »` n'apparaît **que** sur une livraison `dead`, et seulement si l'endpoint
est encore actif.

Chaque ligne se déplie : `« Corps envoyé »` (l'enveloppe `{id, type, data}`) et
`« Réponse reçue »` (code HTTP et corps).

### Rejouer une livraison

`POST /admin/webhook-deliveries/{delivery}/replay`. Le rejeu crée une **nouvelle livraison**
et met l'ancienne en file. Le flash donne le nouvel identifiant :
`« Rejeu mis en file — nouvel id de livraison : {8 chars}… »`

Trois refus :

| Message | Cause |
|---|---|
| `« Identifiant de livraison invalide. »` | l'identifiant n'est pas un UUID |
| `« Livraison introuvable. »` | hors de votre périmètre |
| `« Cette livraison n'est pas en échec ; aucun rejeu nécessaire. »` | la livraison n'est pas morte |
| `« L'endpoint de cette livraison est désactivé : réactivez-le avant de rejouer. »` | l'endpoint est désactivé |

**Ordre à respecter** : corrigez d'abord la cause (le code du projet, l'URL, l'endpoint),
puis rejouez. Rejouer sans corriger reproduit l'échec et consomme des tentatives.

### Le détail d'une livraison

`GET /admin/webhook-deliveries/{delivery}` : `ID livraison`, `Projet`, `ID événement
(X-Event-Id)`, `Tentatives`, `Endpoint`, `Dernière tentative`, `Livrée le`, `Prochain
essai`, `Rejeu de`, `Dernière erreur`. Puis le `« Payload de l'événement »` et la liste
`« Tentatives »` : pour chaque tentative, la date, l'issue (`livré` / `abandon (DLQ)` /
`nouvel essai programmé`), le code HTTP ou `« aucune réponse »`, la durée en ms, l'erreur,
la **requête envoyée** (POST, URL, en-têtes, corps) et la **réponse du récepteur**.

Si l'endpoint est désactivé, l'écran le dit : `« Endpoint désactivé — aucune livraison ni
rejeu possible. »`

---

## 4. Les exceptions d'argent

Une **exception d'argent** s'ouvre quand le Core constate que l'argent reçu ne correspond
pas à ce qui était attendu : un écart de montant, un écart de devise, un trop-perçu, un
paiement tardif, un écart de rapprochement.

`GET /admin/money-exceptions` — lecture `money-exceptions:read`, règlement
`money-exceptions:write`.

### Le tableau

Onglets : `« Toutes »`, `« Ouvertes »`, `« À valider »`, `« Résolues »`.

Colonnes : `Statut` (`Ouverte`, `À valider`, `Résolue`), `Gravité` (`Critique`, `Haute`,
`Moyenne`, `Basse`), `Type`, `Projet`, `Montant`, `En attente au grand livre`, `Ouverte le`,
`Traiter`.

La colonne `« En attente au grand livre »` est celle qui compte : elle dit **combien
d'argent est réellement en jeu**, c'est-à-dire la part que le Core a encaissée mais n'a pas
pu imputer.

### La fiche d'une exception

`GET /admin/money-exceptions/{exception}` : `Montant en jeu`, `En attente au grand livre`,
`Gravité`, `Projet`, `Paiement`, `Ouverte le`, `Résolue le`, `Référence externe`, puis un bloc
`« Décision »` (`Décision`, `Référence`, `Motif`, `Proposée par`, `Validée par`).

### Les quatre décisions possibles

| Décision | Ce qu'elle dit | Ce qu'elle écrit |
|---|---|---|
| `« Remboursé hors plateforme »` | vous avez rendu l'argent en dehors du Core | sortie (`withdrawal`) |
| `« Passé en revenu du projet »` | l'argent reste au projet | revenu (`revenue`) |
| `« Imputé à un wallet »` | l'argent appartient à un compte client | crédit de wallet |
| `« Aucun mouvement (rien n'était écrit) »` | il n'y avait rien à imputer | rien |

### Résoudre

Statut `open` → formulaire `« Décider »` :

1. Choisissez la décision.
2. `« Référence du remboursement »` (facultative).
3. `« Motif »` **obligatoire**, 5 à 1000 caractères.
4. `« Enregistrer la décision »` — **exige un second code**.

Deux résultats possibles :

| Message | Ce que ça veut dire |
|---|---|
| `« Exception résolue : {libellé}. »` | c'est fait, l'argent a été écrit au grand livre |
| `« Résolution enregistrée. Elle attend la validation d'une seconde personne (montant au-delà du seuil). »` | la décision est enregistrée, l'écriture attend une validation |

**Le seuil de double validation** est de 50 000 unités mineures par défaut (configurable par
la plateforme). Au-dessus, l'exception passe à `awaiting_approval`.

### Approuver ou refuser

Statut `awaiting_approval` → bandeau `« Validation par une seconde personne »`.

- Si **vous** avez proposé la résolution : `« Vous avez proposé cette résolution : une autre
  personne doit la valider. »` Les boutons de validation ne sont pas affichés.
- Sinon : `« Valider et écrire au grand livre »` (**second code requis**) et `« Refuser »`
  (pas de second code). `« Résolution refusée : l'exception est de nouveau ouverte. »`

### Le bassin

Ce qui est « en attente au grand livre » est un **compte unique par projet et par devise**,
appelé le **bassin**. Deux exceptions du même projet et de la même devise **partagent** ce
solde.

Concrètement : si le bassin porte 30 000 et qu'une exception de 40 000 arrive, elle est
refusée, même si une autre exception de 10 000 est aussi en attente. Le refus s'affiche comme
un conflit, et le motif est dans le journal.

> Ce comportement est voulu. Il empêche d'imputer une exception à un wallet et de créditer
> une somme qui n'a jamais été encaissée.

---

## 5. Les corrections d'exploitation

Une **correction d'exploitation**(rectifie une écriture au grand livre. Elle ne modifie
jamais une écriture existante : elle en crée une **nouvelle** qui la contre-passe.

`GET /admin/ledger-adjustments` — permission `money-exceptions:read` pour lire,
`money-exceptions:write` pour décider.

Onglets : `« Toutes »`, `« À valider »`, `« Validées »`, `« Refusées »`.

Colonnes : `Statut`, `Projet`, `Source`, `Destination`, `Montant`, `Motif`, `Proposée le`,
`Traiter`.

### Proposer une correction

`GET /admin/ledger-adjustments/create` — le bandeau s'appelle `« Quatre yeux, toujours »`.

1. **Étape 1** : choisissez le projet.
2. **Étape 2**, le formulaire :

| Champ | Contrainte |
|---|---|
| `« Transaction à contrer-passer »` | liste des 100 dernières écritures non-corrections |
| `« …ou une écriture plus ancienne (identifiant) »` | 36 caractères maximum. **L'identifiant saisi ici l'emporte sur la liste.** |
| `« Type de compte débité »` | un des 11 types proposés |
| `« Référence du compte débité »` | 200 caractères |
| `« Type de compte crédité »` | un des 11 types proposés |
| `« Référence du compte crédité »` | 200 caractères |
| `« Montant (unités mineures) »` | 1 minimum. `« 100 XAF = 100 F CFA, 100 USD = 1,00 $ »` |
| `« Devise »` | `XAF` ou `USD` |
| `« Motif »` | **obligatoire**, 5 à 1000 caractères. `« Le motif est obligatoire : il est ce qui reste de la décision quand on la relit dans six mois. »` |

`« Proposer la correction »`.

### Les types de compte

| Type | Ce que c'est |
|---|---|
| `adjustment` | Compte de contrepartie des corrections |
| `bad_debt` | Créances abandonnées |
| `clearing` | Compte de bascule du prestataire |
| `collections` | Encaissements en attente d'imputation |
| `customer_receivable` | Créance client |
| `manual_receipts` | Encaissements hors ligne |
| `payment_exceptions` | Encaissements non imputables |
| `payout_in_transit` | Versements en cours *(déclaré, jamais écrit)* |
| `provider_fees` | Frais du prestataire *(déclaré, jamais écrit)* |
| `revenue` | Revenu du projet |
| `withdrawal` | Sorties enregistrées |

> **`wallet` n'en fait pas partie.** Une correction ne peut pas porter sur le compte d'une
> partie : `« Une correction d'exploitation ne peut pas porter sur le compte d'une partie
> (wallet) : le solde d'une partie n'est mis à jour que par le module wallet. »`

### Trois refus à connaître

| Message | Cause |
|---|---|
| `« La correction doit nommer la transaction qu'elle contre-passe : choisissez-la dans la liste, ou saisissez son identifiant. »` | aucune transaction visée |
| `« Une correction d'exploitation ne peut pas porter sur le compte d'une partie (wallet) : le solde d'une partie n'est mis à jour que par le module wallet. »` | un côté est un `wallet` |
| `« La correction doit porter sur deux comptes différents. »` | même compte des deux côtés |

### Valider ou refuser

Sur la fiche d'une correction `proposed` :

- si **vous** l'avez proposée : `« Vous avez proposé cette correction : une autre personne
  doit la valider. »`
- sinon : `« Valider et écrire au grand livre »` (**second code**) et `« Refuser »` (pas de
  second code). `« Correction refusée : rien n'a été écrit au grand livre. »`

> **Le second regard est obligatoire par défaut.** Le seuil de double validation des
> corrections vaut `0` par défaut : **toute** correction attend une seconde personne.
> Le flash vous dira de qui vous dependez : `« Correction proposée : elle attend la
> validation d'une seconde personne. »`

---

## 6. Ce qui est journalisé, ce qui ne l'est pas

Toutes les actions de ce chapitre sont **auditées**. Le journal d'audit contient, pour
chacune : l'acteur, l'action, la ressource, la corrélation, et le détail `Avant` / `Après`
(voir le chapitre 01).

**Jamais en clair** dans les journaux : mots de passe, clés d'API, clés privées de
prestataire, signatures, secrets de webhook, numéros de carte.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Paiements | `app/Http/Controllers/Admin/PaymentsController.php` |
| Réconciliation | `app/Http/Controllers/Admin/ReconciliationController.php` |
| Livraisons de webhooks | `app/Http/Controllers/Admin/WebhookDeliveriesController.php` |
| Exceptions d'argent | `app/Http/Controllers/Admin/MoneyExceptionsController.php`, `packages/payment/src/Application/UseCase/ResolveMoneyExceptionUseCase.php` |
| Corrections d'exploitation | `app/Http/Controllers/Admin/LedgerAdjustmentsController.php`, `packages/ledger/src/Application/UseCase/LedgerAdjustmentsUseCase.php` |
| Le bassin et son verrou | `packages/payment/src/Infrastructure/Persistence/Eloquent/PostgresMoneyPoolLock.php` |
| Seuils de double validation | `config/payment.php`, `config/ledger.php` |
| Règles | `docs/adr/0077-confirmation-des-encaissements-et-exceptions.md`, `0076-grand-livre-complet-plan-de-comptes.md`, `0078-rapprochement-et-controles-d-invariants.md` |
