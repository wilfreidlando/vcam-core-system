# 04 — Commerce

*Ce chapitre est la référence technique du commerce : clients, offres, abonnements, factures, encaissements. La procédure est dans les [parcours F05 à F08](../parcours/README.md).*

Clients, offres, abonnements, factures, encaissements. C'est le chapitre le plus long de
la console, parce que c'est celui qui produit de l'argent — et c'est aussi celui où les
distinctions sont les plus nettes.

---

## 1. Les cinq objets, dans l'ordre

```
client  ──►  abonnement  ──►  période  ──►  facture  ──►  encaissement
             (offre + version)                          (en ligne ou hors ligne)
```

| Objet | Ce que c'est | Qui le crée |
|---|---|---|
| **Client** | une personne ou une société facturée par le projet. Le projet la déclare avec **sa** propre référence. | le projet (API) ou la console |
| **Offre** (`plan`) | un catalogue : un code stable et des **versions** | la console ou l'API |
| **Version** | un prix, une périodicité, un essai, des fonctionnalités. **Publiée, elle est figée.** | la console ou l'API |
| **Abonnement** | le contrat entre un client et une offre, découpé en **périodes** | la console, l'API ou le portail |
| **Facture** | un document figé à l'émission, numéroté sans trou | la console ou l'API |
| **Encaissement** | l'argent qui entre. En ligne (un paiement) ou hors ligne (un virement, un espèces) | la console ou le portail |

**La règle qui surprend le plus : une période d'abonnement déclarée payée n'est pas de
l'argent.** C'est un **fait enregistré**. Le chemin qui bouge l'argent est l'**encaissement**
— une facture payée, ou un reçu hors ligne validé. L'écran d'abonnement affiche d'ailleurs
l'avertissement : `« Cette déclaration n'écrit aucun mouvement au grand livre »`.

---

## 2. Les clients

`GET /admin/projects/{projectId}/customers` — lecture `customers:read`, écriture
`customers:write`.

### Créer un client

`GET .../customers/create` — formulaire `« Nouveau client »` :

| Champ | Rôle | Contrainte |
|---|---|---|
| `« Référence chez le projet »` | l'identifiant opaque **que le projet connaît** | obligatoire, 128 caractères, `[A-Za-z0-9._:-]+` |
| `« Nom affiché »` | obligatoire | 200 caractères |
| `« Contact (courriel) »` | facultatif | 255 caractères |
| `« Téléphone »` | facultatif | 32 caractères |
| `« Langue »` | facultatif | 16 caractères, `fr` par défaut |

`« Déclarer le client »` → `« Client {ref} créé. »`

Le formulaire ne comporte que ces **cinq champs**, et il est rendu **côté
serveur** : un champ par attribut, jamais une zone de saisie JSON.

> **Qui déclare le client.** L'opérateur de la console, pour le projet. Une
> **application cliente** ne le déclare pas : son gabarit de portées ne porte pas
> `customers:write`, et le Core lui **pousse** le client qu'il a créé
> (`customer.created`) — voir
> [ADR-0110](../../adr/0110-le-core-cree-le-locataire-et-le-pousse.md).
>
> **Le contact n'est jamais affiché en clair.** Le courriel et le téléphone sont masqués
> partout dans la console et dans le portail. À l'édition, le champ est vide : c'est
> normal. Un champ vide **conserve** la valeur existante ; pour changer une valeur,
> saisissez la nouvelle. Le grand livre ne contient jamais de donnée personnelle.

### La forme que l'application a déclarée

L'application cliente peut annoncer **la forme de ses clients** : la clé unique
qu'elle utilise, le format que ses références doivent respecter, et les champs
qu'elle attend du Core (ADR-0122).

- **Proposer** — `PUT /v1/projects/{projectId}/tenant-schema`, portée
  `tenant_schema:write` (dans le modèle **Application cliente**) :

  ```json
  {
    "unique_key": "code",
    "unique_key_pattern": "AE[0-9]{4,8}",
    "fields": [
      {"key": "localisation", "label": "Localisation", "type": "string",
       "required": false, "max_length": 120, "source": "declared.localisation"}
    ]
  }
  ```

  La réponse est `201` ; la déclaration reste **en attente** tant que
  l'opérateur ne l'a pas lue. Une source `core_identity.email` (ou `display_name`,
  `phone`, `locale`) **remplace** le champ correspondant du Core : la console ne
  demande jamais la même valeur deux fois.

- **Lire** — `GET /v1/customers/{externalRef}` rend `declared_attributes` (avec
  `customers:read`) : un objet par clé déclarée, `{}` quand il n'y en a pas.

- **Décider** — dans la console, page **« Forme du client »** du projet
  (`GET /admin/projects/{projectId}/customers/schema`) : la proposition est lue
  comme une **liste** (libellé, type, obligatoire, origine, longueur maximale),
  jamais comme du JSON. **« Accepter la déclaration »** la met en vigueur ;
  **« Refuser la déclaration »** laisse la forme précédente.

Une fois la forme acceptée, le formulaire **« Nouveau client »** rend **un champ
par clé déclarée**, et le format de la référence est écrit sous le champ
**« Référence chez le projet »**. Une référence qui ne respecte pas ce format est
refusée **sur place**, sous le champ, avec la raison.

### Les clients que l'application avait déjà

Page **« Importer des clients »**
(`GET /admin/projects/{projectId}/customers/import`) : l'opérateur dépose un
fichier CSV dont l'en-tête est `external_ref, display_name, email, phone`.

Le Core crée les clients et les pousse ; l'application, qui connaît déjà ses
clients, les **relie** au lieu d'en créer un second. Un client déjà connu est
mis à jour, jamais dupliqué. Le rapport dit ce qui a été créé, ce qui était déjà
connu, et **pour chaque ligne refusée, sa raison** (une référence hors format,
une ligne illisible).

> Une application cliente propose elle-même ses clients : elle n'écrit **jamais**
> un client chez le Core (ADR-0110). L'import est un geste d'opérateur, dans la
> console.

### Modifier, suspendre, réactiver, effacer

| Action | Bouton | Effet |
|---|---|---|
| Modifier | `« Modifier »` | la `« Référence chez le projet »` est **en lecture seule**. Un champ caché `version` protège contre l'écrasement concurrent. |
| Suspendre | `« Suspendre »` (si actif) | `« Client {ref} suspendu. »` Le client ne peut plus s'abonner. |
| Réactiver | `« Réactiver »` (si suspendu) | `« Client {ref} réactivé. »` |
| Effacer | `« Effacer »` (si non effacé) | `« Client {ref} effacé. »` |

**Effacer est définitif.** L'identité est détruite ; la ligne et l'historique restent, pour
que les factures passées gardent leur sens. Une facture émise sur un client effacé
continue d'exister. Un client effacé ne peut pas être réactivé — il faut le déclarer à
nouveau.

L'effacement est journalisé et **plafonné**, mais il ne demande **pas** de second code :
il ne déplace pas d'argent.

### La fiche d'un client

`GET .../customers/{externalRef}` :

- statut, **« Droits et usage »** (les compteurs `limit` et les options activées) ;
- `« Abonnements du client »` : `Offre`, `Statut`, `Tarif`, `Période en cours` ;
- `« Factures récentes du client »` : `Numéro`, `Statut`, `Total`, `Payé`, `Échéance` ;
- formulaire `« Souscrire à une offre »` : `plan_code` (liste) et `trial_days`
  (`« Essai (jours) »`). Visible seulement si vous avez `subscriptions:write`.

---

## 3. Les offres et leurs versions

`GET /admin/projects/{projectId}/plans` — lecture `plans:read`, écriture `plans:write`.

### Créer une offre

`GET .../plans/create` porte **deux formulaires**.

**« Créer le plan »** :

| Champ | Contrainte |
|---|---|
| `« Code »` | obligatoire, minuscules, chiffres, `-` et `_`, 64 caractères. Le code est **stable** : il ne change pas quand le prix change. |
| `« Visibilité »` | `« Proposé publiquement »` ou `« Attribué seulement »` |
| `« Nom »` | obligatoire, 200 caractères |
| `« Description »` | 2000 caractères |

`« Créer le plan »` → `« Plan {code} créé — ajoutez-lui une version. »`

**« Ajouter une version »** :

| Champ | Contrainte |
|---|---|
| `« Offre (code) »` | obligatoire — l'offre est nommée **dans le formulaire**, pas dans l'URL |
| `« Prix (unités mineures) »` | 0 par défaut |
| `« Devise »` | `XAF` par défaut |
| `« Unité »` + `« Nombre »` | jour / semaine / mois / an ; 1 à 365, 1 par défaut |
| `« Essai (jours) »` | facultatif |
| `« Grâce (jours) »` | 0 à 90 |
| `« Maintenir l'accès pendant la grâce »` | décochée, les droits du client sont fermés dès l'impayé |

> **Les montants sont en unités mineures.** 50 000 XAF se saisit `50000`. 100 USD se saisit
> `100` et représente 1,00 $. C'est une règle générale du Core : jamais de montant à
> virgule.

### Les fonctionnalités de la version

Le cadre **« Fonctionnalités de la version »** se remplit **bloc par bloc** : l'écran
propose **trois blocs**, un par fonctionnalité, et l'opérateur n'écrit **jamais** de JSON.
Un bloc dont le `« Nom »` est vide est **ignoré** — les deux autres blocs restent disponibles.

Chaque bloc demande d'abord deux choix, puis **les réglages du type choisi** :

| Champ | Ce qu'il fait |
|---|---|
| `« Nom »` | le nom de la fonctionnalité, **celui du projet** : le Core n'en connaît aucun. Minuscules, chiffres, `« . »` et `« _ »`, 64 caractères au plus. |
| `« Type »` | `« Interrupteur : activée ou non »` ou `« Limite d'usage d'un compteur »`. |

| Type | Les champs qui comptent |
|---|---|
| `« Interrupteur »` | `« Activée »` — `« Oui »` ou `« Non »`. |
| `« Limite d'usage »` | `« Compteur »` (obligatoire), `« Limite »` (le nombre autorisé ; vide = illimité), `« Remise »` (`« À chaque période »` ou `« Jamais »`), `« Dépassement »` (`« Bloquer »` ou `« Autoriser et signaler »`), `« Seuil d'alerte (points de base) »` (facultatif : 8000 = 80 % ; vide = pas d'alerte). |

Les champs de l'autre type sont **ignorés** : un interrupteur n'a pas de compteur, une
limite d'usage n'a pas d'« Activée ». Un bloc nommé mais incomplet est **refusé**, et le
message dit ce qui manque : `« Choisissez le type de la fonctionnalité « … ». »`,
`« Dites si la fonctionnalité « … » est activée. »`,
`« Indiquez le compteur que compte la fonctionnalité « … ». »`.

> **Trois emplacements, pas plus.** La console en propose trois par version ; une offre
> qui en porte davantage se déclare par l'API (`POST /v1/plans/{code}/versions`).

### Publier une version

Une version **publiée est figée** : prix, périodicité, essai, fonctionnalités — plus rien ne
change. La publication est une action irréversible (une confirmation le dit).

`« Publier »` n'apparaît que sur une version `Brouillon`. `« Version {N} publiée — elle est
désormais figée. »`

Les statuts d'une version : `Brouillon`, `Publiée`, `Retirée`.

### Archiver une offre

`« Archiver »` → `« Plan {code} archivé — il n'accepte plus de nouvel abonnement. »` Les
abonnements en cours ne sont pas touchés.

---

## 4. Les abonnements

`GET /admin/projects/{projectId}/subscriptions` — lecture `subscriptions:read`, écriture
`subscriptions:write`.

| Colonne | Ce qu'elle contient |
|---|---|
| `Client` | la référence |
| `Plan` | le code et la version (`{code} v{n}`) |
| `Prix` | montant et périodicité |
| `Statut` | `Incomplet`, `Essai`, `Actif`, `Impayé`, `En pause`, `Annulé`, `Expiré` |
| `Période courante` | `« jusqu'au {date} »` |
| `Échéance` | `« essai jusqu'au {date} »` |

> L'écran n'expose **aucun filtre**. Le contrôleur accepte `customer_ref`, `status`,
> `cursor` et `limit`, mais seule la pagination est dans l'interface. Pour chercher, allez
> par la fiche du client.

### La fiche d'un abonnement

`GET .../subscriptions/{subscriptionId}`. Trois cartes : `Statut`, `Prix signé`, `Période
courante`. Puis le tableau `« Périodes de l'abonnement »` : `#`, `Type`, `Début`, `Fin`,
`Réglée` (oui/non), `Motif`.

Les gestes disponibles :

| Bloc | Geste | Ce qu'il faut |
|---|---|---|
| **Suspendre / reprendre** | `« Suspendre »` / `« Reprendre (la période est prolongée) »` | `« Motif de la suspension »` **obligatoire**. Motif vide → `422 « Le motif est obligatoire. »` |
| **Mois offert** | `« Accorder »` | `« Durée offerte (jours) »` (1 à 365, 30 par défaut) + `« Motif »` obligatoire |
| **Changer de plan** | `« Changer de plan »` | `plan_code` + `effective` : `« à l'échéance »` ou `« immédiat »` |
| **Déclarer un règlement** | `« Déclarer un règlement »` | `seq` (« Période à régler ») + `reference` (« Référence du règlement ») |
| **Annuler** | `« Annuler »` | `at_period_end` (`« à l'échéance »` / `« immédiat »`), motif facultatif |

**La déclaration d'un règlement ne bouge pas d'argent.** Elle marque une période comme
réglée ; c'est la facture qui, encaissée, fera le mouvement au grand livre. L'écran le
rappelle en clair.

Tous ces gestes sont **journalisés**.

---

## 5. Les factures

`GET /admin/projects/{projectId}/invoices` — lecture `invoices:read`, écriture
`invoices:write`.

**Filtre** : `« Statut »` (Tous / Brouillon / Ouverte / Payée / Annulée / Irrécouvrable).
**Colonnes** : `Numéro` (`« brouillon »` si la facture n'a pas encore de numéro), `Client`,
`Total`, `Statut`, `Échéance`.

### Créer une facture

`GET .../invoices/create` — bouton `« Créer le brouillon »` (le nom est exact : **rien n'est
émis** à ce stade).

| Champ | Contrainte |
|---|---|
| `« Référence du client »` | obligatoire |
| `« Devise »` | 3 caractères |
| `« Taxe (points de base) »` | 0 à 10000. 1000 points de base = 10 %. |
| `« Échéance »` | date |
| `lines[i][description]` | `« Désignation »`, obligatoire, 500 caractères |
| `lines[i][quantity]` | `« Qté »`, 1 minimum |
| `lines[i][unit_amount_minor]` | `« Prix unitaire »`, en unités mineures |
| `lines[i][tax_rate_bps]` | `« Taxe (points de base) »`, par ligne |

Trois lignes sont proposées ; les lignes vides sont ignorées.

> La taxe est **calculée et stockée par ligne**, avec un arrondi au demi-supérieur exact.
> Deux lignes à 10,005 et 10,005 donnent 20,01 et non 20,02. Le total d'une facture est
> toujours la somme de ses lignes taxées.

### La fiche d'une facture

`GET .../invoices/{invoiceId}` :

- en-tête : `Numéro`, `Statut` ;
- chiffres : `Émise le`, `Échéance` (`« à réception »` si vide), `Sous-total`, `Taxe`,
  `Total`, `Déjà réglé` ;
- `« Lignes de la facture »` : `Désignation`, `Qté`, `Prix unitaire`, `Montant`, `Taxe` ;
- `« Encaissements de la facture »` : `Origine` (`« Paiement en ligne »` ou `« Hors ligne —
  Virement | Espèces | Chèque | Autre »`), `Montant`, `Référence`, `Date`, `État`.

### Les trois gestes qui exigent un second code

Ce sont des actions **qui déplacent de l'argent**. La console vous renvoie vers
`« Confirmez votre identité »` (voir le chapitre 01).

| Geste | Quand | Ce qu'il faut |
|---|---|---|
| `« Émettre »` | statut `Brouillon` | confirmation. « Une facture émise est figée. » |
| `« Annuler la facture »` | statut `Ouverte` | `« Motif de l'annulation »` obligatoire |
| `« Passer en irrécouvrable »` | statut `Ouverte` | `« Motif de l'écriture »` obligatoire |

Émettre inscrit la créance au grand livre. Annuler la contre-passe. Passer en
irrécouvrable transforme la créance en perte.

> **Une facture émise ne se modifie plus.** Une erreur de prix se corrige par une
> correction d'exploitation (chapitre 05), pas en réémettant.

---

## 6. Les encaissements hors ligne

Un **encaissement hors ligne**, c'est de l'argent reçu par un canal que le Core ne voit pas :
un virement bancaire, un espèces, un chèque. On enregistre le fait ; la comptabilisation
vient après.

### Enregistrer un encaissement

Sur la fiche d'une facture **ouverte** (`status === 'open'`), formulaire
`« Encaissement hors ligne »` :

| Champ | Contrainte |
|---|---|
| `« Méthode »` | `Virement`, `Espèces`, `Chèque`, `Autre` |
| `« Référence »` | obligatoire, 200 caractères (le numéro du virement, la référence du chèque) |
| `« Montant (unités mineures) »` | obligatoire, 1 minimum |
| `« Devise »` | obligatoire, 3 caractères |
| `« Reçu le »` | obligatoire, date |
| `« Note »` | 1000 caractères |

`« Enregistrer l'encaissement »` → deux messages possibles :

| Message | Ce que ça veut dire |
|---|---|
| `« Encaissement hors ligne enregistré : il attend sa validation. »` | il est **au-dessus** du seuil : il attend un acte de validation |
| `« Encaissement hors ligne enregistré et comptabilisé. »` | il est **sous** le seuil : il est déjà comptabilisé |

Le seuil est un réglage du projet : `« Seuil de validation d'un reçu hors ligne (unités
mineures) »`, dans `« Réglages du commerce »`. `0` = toujours valider.

### Valider ou refuser

`GET /admin/projects/{projectId}/receipts` — trois onglets : `« À valider »` (par défaut),
`« Comptabilisés »`, `« Refusés »`.

Colonnes : `Référence`, `Montant`, `Méthode`, `Reçu le`, `État`, `Validation`.

Deux boutons : `« Valider »` et `« Refuser »`. **La validation exige un second code** (elle
écrit au grand livre). Le refus non.

`« Encaissement hors ligne validé : il est comptabilisé. »` / `« Encaissement hors ligne
refusé. »`

> **Un reçu hors ligne approved par une seule personne est possible.** Depuis la décision
> ADR-0095, l'auto-validation est permise ; c'est le **montant** qui décide s'il faut un
> second regard, pas le nombre de mains. Les autres contrôles à quatre mains — exceptions
> d'argent et corrections du grand livre — ne bougent pas.

---

## 7. Les réglages du commerce

`GET /admin/projects/{projectId}/commerce-settings` — permission `plans:write`.

### Section « Facturation »

| Champ | Ce qu'il fait |
|---|---|
| `« Devise par défaut »` | 3 caractères |
| `« Fuseau horaire des périodes »` | les périodes d'abonnement sont calculées dans ce fuseau |
| `« Préfixe des factures »` | `[A-Z0-9]{2,12}`. La numérotation est **sans trou** : `FACT-2026-000042`. |
| `« Taxe par défaut (%) »` | convertie en points de base |
| `« Facture du renouvellement émise (jours avant) »` | 0 à 90 |
| `« Délai pour payer la première facture (heures) »` | 1 à 720. Passé ce délai, l'abonnement `Incomplet` expire. |
| `« Seuil de validation d'un reçu hors ligne (unités mineures) »` | 0 = toujours valider |

### Section « Quotas »

| Champ | Ce qu'il fait |
|---|---|
| `« Ce que la plateforme compte pour vous »` | les correspondances **capacité → compteur**, une par bloc : `« Capacité »` (ce que le client consomme) et `« Compteur »` (ce que la plateforme compte) |
| `« Catalogue de packs »` | ce que les clients peuvent acheter **en plus** de leur quota — un pack par bloc : `« Code »`, `« Compteur »`, `« Quantité »`, `« Prix (unités mineures) »` et `« Libellé »` (voir [11 — Les suppléments de quota](11-supplements-de-quota.md)) |
| `« Rendre l'unité quand un message n'a atteint personne »` | rembourse le quota d'un envoi échoué |
| `« Exiger customer_ref sur tout appel mesuré »` | refuse une mesure sans client |

Les deux se remplissent **bloc par bloc**, dans la section **« Limites d'usage »** de
l'écran. Il y a **au moins trois blocs** de chaque sorte, et autant que ce qui est déjà
enregistré. Trois règles :

- une correspondance dont **une seule** des deux cases est remplie est **ignorée** ;
- un pack dont **tous** les champs sont vides est **ignoré**, mais un pack à **un seul
  champ vide** est **refusé** ;
- le `« Compteur »` d'un pack doit être **un compteur déclaré** dans les
  correspondances ci-dessus — sinon la saisie est refusée.

Chaque enregistrement est journalisé (`billing.settings_changed`).

---

## 8. Les suppléments de quota

Un quota épuisé ne se débloque pas seulement en renouvelant l'abonnement : une **quantité
achetée en plus** peut s'ajouter au compteur, sur le même abonnement. Le mécanisme — la
limite effective, le cycle de vie, l'API d'achat et ce que le portail en fait — est
expliqué dans **[11 — Les suppléments de quota](11-supplements-de-quota.md)**.

En résumé : l'achat passe par l'API opérateur, le supplément ne compte qu'une fois sa
facture réglée, et le portail affiche la limite augmentée.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Clients | `app/Http/Controllers/Admin/BillingCustomersController.php`, `…/CustomerOverviewController.php` |
| Offres | `app/Http/Controllers/Admin/BillingPlansController.php` |
| Abonnements | `app/Http/Controllers/Admin/BillingSubscriptionsController.php` |
| Factures | `app/Http/Controllers/Admin/BillingInvoicesController.php` |
| Encaissements hors ligne | `app/Http/Controllers/Admin/BillingInvoiceReceiptsController.php` |
| Réglages du commerce | `app/Http/Controllers/Admin/ProjectCommerceController.php` |
| Règles du domaine | `docs/adr/0070-clients-d-un-projet.md`, `0071-catalogue-plans-versionnes-et-fonctionnalites.md`, `0072-abonnements-contrat-periodes-et-machine-d-etats.md`, `0073-factures-encaissement-et-paiements-hors-ligne.md`, `0094-lot-11-factures-et-encaissement.md`, `0095-encaissement-hors-ligne-validation-a-un-compte.md` |
