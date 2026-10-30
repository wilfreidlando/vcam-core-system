# 09 — Comprendre l'argent

On ne peut pas exploiter correctement le Core sans savoir comment l'argent y bouge. Ce
chapitre explique le fonctionnement interne, avec un seul but : que vous puissiez répondre
à un client qui demande « d'où vient ce montant ? ».

---

## 1. Le grand livre : une somme nulle, toujours

Le **grand livre** (*ledger*) est la source de vérité financière du Core. Toutes les sommes
qui entrent ou qui sortent y sont écrites. Il n'y a pas d'argent « dans le système » qui
n'ait pas de ligne.

### La règle unique

Une **transaction** porte **au moins deux lignes**, et **la somme des lignes vaut zéro**.

```
Crédit  collections           + 5 000
Débit   customer_receivable   - 5 000     → somme nulle
```

Autrement dit : **ce qui est crédité est débité**. Chaque franc qui entre quelque part sort
quelque part d'autre. Il n'y a pas de source, il n'y a pas de gisement : le grand livre ne
connaît que des mouvements.

Cette règle est vérifiée **deux fois**, indépendamment :

1. **Dans le code**, au moment de construire la transaction : si la somme n'est pas nulle,
   la transaction est refusée avant d'être écrite.
2. **En base**, à la fin de la transaction SQL : un déclencheur (`trigger`) relit les lignes
   et refuse l'écriture si le total n'est pas nul **par devise**.

C'est pour cela que le grand livre ne peut pas se déséquilibrer : deux contrôles
indépendants, l'un applicatif, l'autre en base.

### Un compte, pas un solde

Chaque **compte** est identifié par quatre éléments :

| Élément | Exemple |
|---|---|
| Le projet | « Boutique Orange CI » |
| Le **type** de compte | `customer_receivable`, `revenue`, `clearing`, `collections`… |
| La **référence** | l'identifiant du client, ou le code du prestataire |
| La devise | `XAF` |

**Les comptes sont cloisonnés par projet.** Un déclencheur refuse qu'une écriture touche
deux projets différents. L'argent ne peut pas passer d'un projet à l'autre, même par un
bug.

### Les types de compte que vous croiserez

| Type | Ce que c'est | Qui l'écrit |
|---|---|---|
| `clearing:<prestataire>` | ce que le Core doit au prestataire | le module paiement |
| `collections` | un encaissement en attente d'imputation sur une facture | le module facturation |
| `payment_exceptions` | **le bassin** : un encaissement non imputable | le module paiement |
| `customer_receivable` | une créance sur un client | le module facturation |
| `revenue` | le revenu du projet | le module facturation |
| `manual_receipts:<moyen>` | un encaissement hors ligne validé | le module facturation |
| `bad_debt` | une créance abandonnée | le module facturation |
| `withdrawal` | une sortie enregistrée | le module portefeuille |
| `wallet:<id>` | le compte jumeau d'un portefeuille | le module portefeuille |

> **Deux types sont déclarés mais jamais écrits par le code** : `payout_in_transit`
> (versements en cours) et `provider_fees` (frais du prestataire). Ils apparaissent dans la
> liste de l'écran de correction, mais aucune écriture ne les crée. Voyez le chapitre 10.

### Rien ne se modifie : tout se contre-passe

**Les écritures sont immuables.** Ni modification, ni suppression, ni vidage de table ne
sont possibles — c'est pose par des déclencheurs en base, pas seulement par convention. Une
erreur ne se corrige pas en réécrivant : elle se corrige en **ajoutant** une transaction
nouvelle qui contre-passe l'ancienne.

C'est pour cela que l'écran `« Corrections d'exploitation »` exige toujours de nommer
**la transaction à contrer-passer** (`« La correction doit nommer la transaction qu'elle
contre-passe »`). Une correction sans cible n'est pas une correction.

### L'immuabilité a une contrepartie

Vous ne pouvez pas **modifier** une écriture. Mais vous pouvez en **ajouter** une, et une
addition de lignes fait queue. C'est voulu : la traçabilité vaut mieux qu'une correction
silencieuse.

---

## 2. La balance : lire l'écran « Grand livre »

`GET /admin/projects/{projectId}/ledger` — permission `wallets:read`.

### La balance de vérification

Le tableau principal est la **balance** : pour chaque compte et chaque devise, le total
débité, le total crédité, et le **solde net (débit)**.

```
Compte              Référence        Débit      Crédit     Solde net (débit)
collections                          120 000     120 000              0
customer_receivable  client-42        50 000      50 000              0
```

**La somme de la colonne « Solde net » doit être nulle pour chaque devise.** C'est la
balance de vérification : c'est le contrôle que l'écran du grand livre affiche en
bandeau.

| Bandeau | Ce que ça veut dire |
|---|---|
| `« Équilibrée · somme nulle »` | tout va bien |
| `« Déséquilibre de {montant} »` | **quelque chose ne va pas.** C'est le seul état qui doit vous alerter. |

**Comment vérifier vous-même, sur l'écran :**

1. Notez le solde net de chaque ligne, pour **une seule devise** (n'additionnez pas des
   devises différentes).
2. Ajoutez-les.
3. Le total doit valoir **exactement zéro**.

S'il ne vaut pas zéro, **ne touchez à rien** : ouvrez une ticket, et dites que la balance
n'est pas équilibrée, en précisant la devise. Un déséquilibre est un incident technique,
pas un geste d'exploitation.

> Un contrôle automatique fait la même chose **toutes les heures**, sur les deux dernières
> heures. S'il détecte un écart, il ouvre une exception d'argent de gravité critique. Vous
> la verrez dans l'écran `« Exceptions d'argent »`.

### Les transactions

Le second tableau liste les **30 dernières transactions**, avec un filtre sur le type
(`payment_collection`, `wallet_credit`, `invoice_issue`, `adjustment`…). Chaque ligne montre
la date, le type, la référence et **le détail débit/crédit de chaque ligne**.

C'est l'écran qui répond à « d'où vient ce montant ? » : prenez la transaction, lisez ses
lignes, et vous savez de quel compte vers quel compte l'argent est passé.

---

## 3. Les portefeuilles : deux vues du même argent

Un **portefeuille** est un compte de valeur rattaché à un tiers, dans **une seule devise**.
Un tiers qui détient deux devises a deux portefeuilles.

### Disponible et bloqué

Le solde d'un portefeuille est **matérialisé** : il est stocké, pas recalculé à chaque
lecture. Il est mis à jour **dans la même transaction** que les écritures qui le justifient,
sous un verrou de ligne.

| Champ | Ce que c'est |
|---|---|
| **Disponible** | ce que le tiers peut dépenser tout de suite |
| **Bloqué** | ce qui est retenu |

> **En pratique, le champ « bloqué » vaut toujours zéro.** Il existe en base et dans le
> modèle, mais **aucun mécanisme de gel, de séquestre ou de retenue n'est livré**. Ne
> promettez pas à un client qu'un montant peut être « mis de côté » : ce n'est pas
> possible aujourd'hui.

### Le virement à deux jambes

Un transfert ne crée pas d'argent : il le déplace. Chaque jambe produit **deux lignes** —
on débite un compte, on crédite l'autre. Un transfert compte de 1 à 20 jambes.

```
Crédit  wallet:client-42       + 10 000
Débit   wallet:client-17       - 10 000     → somme nulle
```

Le transfert est **atomique** : toutes les jambes passent, ou aucune. Les portefeuilles
concernés sont verrouillés **par ordre d'identifiant croissant**, pour que deux transferts
qui se croisent ne se bloquent pas mutuellement.

Si les fonds manquent : `409 insufficient-funds`, et **rien n'est bougé**.

### La sortie enregistrée

Le retrait d'un portefeuille n'est pas un transfert : c'est une **sortie enregistrée**. Le
Core ne déplace pas l'argent — il enregistre **que** l'argent a quitté sa garde. La preuve reste
chez vous, dans `externalReference`.

```
Débit   wallet:client-42       - 25 000
Crédit  withdrawal             + 25 000     → somme nulle
```

### Le compte `wallet:` est le jumeau du solde

Dans la balance, chaque portefeuille a un **compte jumeau** (`wallet:<id>`). Le solde
disponible du portefeuille et le solde de ce compte sont **la même somme vue de deux
côtés** : l'un pour l'exploitation, l'autre pour la comptabilité.

> **Précision honnête :** le Core ne compare pas explicitement, à chaque instant, le solde
> affiché et la somme des lignes du compte. Ce qui est vérifié, c'est au niveau du
> **projet** et par devise : la balance vaut zéro, et la somme des portefeuilles ne peut pas
> dépasser la somme des comptes `clearing` (invariant « aucune valeur créée »).

---

## 4. La chaîne de l'argent, de bout en bout

### Les états d'un paiement

```
pending ──► processing ──► succeeded ──► refunded
    │            │             │
    └────────────┴─────────────┴──► failed / expired / canceled
```

| État | Final ? |
|---|---|
| `pending`, `processing` | non — le paiement peut encore évoluer |
| `succeeded`, `failed`, `expired`, `refunded`, `canceled` | **oui** |

**Les transitions sont autorisées dans un seul sens.** `succeeded` ne peut aller que vers
`refunded`. Un état final ne bouge plus. Une transition refusée est **journalisée** dans la
chronologie du paiement (`Statut ignoré`) : un callback tardif ne peut pas faire reculer un
paiement réglé.

### Le canal et le prestataire

Le **canal** est ce que votre client choisit (`mtn_momo`, `orange_money`, `card`). Le
**prestataire** est l'adaptateur technique qui exécute. Le Core route `(canal, projet) →
prestataire`, et **épingle le prestataire au moment de la création** du paiement : un
callback ultérieur ne devine jamais, il va droit à l'adaptateur concerné.

### Ce qui se passe quand le paiement est confirmé

**Un paiement confirmé n'écrit au grand livre que dans un seul cas par défaut, et le
choix est fait par l'appelant, à l'initiation.**

| Ce que vous avez fourni | Ce qui est écrit |
|---|---|
| `wallet_recipient_external_ref` | D `clearing:<prestataire>` / C `wallet:<partie>` |
| rien | D `clearing:<prestataire>` / C `revenue` — **encaissement pur** |
| `purpose = invoice` | D `clearing:<prestataire>` / C `collections` |
| le prestataire a confirmé un **autre montant ou une autre devise** | D `clearing:<prestataire>` / C `payment_exceptions` — **aucun** crédit, **aucune** imputation |

> **Le cas par défaut est l'encaissement pur.** Un paiement réussi ne crédite **jamais**
> automatiquement un compte : le crédit doit être demandé au moment où le paiement est
> créé. C'est une décision d'architecture : « encaisser » et « créditer un compte » sont
> deux capacités distinctes, et un projet peut vouloir l'une sans l'autre.

**La clé d'idempotence du règlement est l'identifiant du paiement.** Un paiement ne peut
donc produire qu'**un seul** règlement, quel que soit le chemin emprunté. C'est le garde-fou
contre le double crédit.

### La boîte d'envoi transactionnelle

Quand l'état d'un paiement change, le Core écrit **dans la même transaction SQL** :

1. le nouvel état du paiement,
2. l'écriture au grand livre,
3. l'**événement** dans une table d'attente (`event_outbox`),
4. le crédit du portefeuille, si demandé.

Un relais, planifié **chaque minute**, relit la table et publie les événements. Si le Core
tombe entre l'écriture métier et la publication, l'événement est republié au tour
suivant : **la livraison est « au moins une fois »**, jamais « exactement une fois ». Vos
consommateurs doivent être idempotents.

> **Aucune écriture métier n'est jamais publiée sans passer par cette table.** C'est un
> garde-fou vérifié automatiquement dans le code.

### Le portail et les webhooks dans la chaîne

| Acteur | Rôle |
|---|---|
| **Le portail** | c'est une **action utilisateur** qui déclenche un paiement API. Le client clique `« Payer »` ; le portail appelle l'API ; la clé d'idempotence dérive d'un nonce de formulaire, donc un double-clic ne paie pas deux fois. |
| **Les webhooks** | c'est la **notification sortante** de l'état. Un module publie l'événement, le relais le reprend, chaque endpoint correspondant reçoit une livraison signée. |
| **Le `callback_url` du paiement** | c'est une notification de l'état d'**un** paiement, vers **une** URL. Si un paiement a un `callback_url`, l'état **n'est pas** envoyé aux endpoints webhook du projet. |

---

## 5. Le compteur d'usage et les quotas

### Ce qu'il compte

Un **compteur** (*meter*) mesure une quantité consommée. Chaque mesure est un **journal**
append-only (`usage_records`) et une **incrémentation** d'un compteur (`usage_counters`).

| Table | Rôle |
|---|---|
| `usage_records` | le **journal** : qui, quoi, combien, quand, avec quelle clé d'idempotence. On n'y insère que. |
| `usage_idempotency` | la **primitive d'unicité** : une clé + un compteur = une seule mesure, même si deux processus courent en même temps. |
| `usage_counters` | la **projection** : le total consommé sur la fenêtre en cours, avec sa limite. |

### L'atomicité : pourquoi vous ne pouvez pas dépasser

Le décompte et la vérification de la limite sont **dans la même instruction SQL**. Le verrou
de ligne que prend cette instruction sérialise les appelants concurrents : **aucun
dépassement n'est possible sous concurrence**.

Si la limite est franchie, l'appel est **refusé et rien n'est écrit** — ni le journal, ni le
compteur, ni la clé d'idempotence. L'appel peut être rejoué plus tard.

### La fenêtre de renouvellement

Les limites viennent de l'abonnement :

| Réglage de l'offre | Ce que ça donne |
|---|---|
| remise à zéro **par période** | la fenêtre est la période courante de l'abonnement |
| **jamais** de remise à zéro | une **jauge** : le compteur monte et ne redescend pas |

**La limite est figée au moment où la fenêtre s'ouvre.** Changer d'offre en cours de
période n'y change rien ; un changement immédiat ouvre une nouvelle période, donc une
nouvelle fenêtre.

### Les seuils

Le seuil d'alerte est un **paramètre de l'offre** (`threshold_bps`, en points de base). Ce
n'est pas une constante du produit : l'onglet `« Au-delà de 80 % »` de l'écran d'usage
liste les compteurs qui ont franchi **le seuil que l'offre définit**, pas nécessairement
80 %.

Quand un seuil est franchi, **deux événements** sont publiés dans la transaction qui le
franchit : `usage.threshold_reached` et `usage.limit_reached`. Ils sont donc livrables par
webhook — c'est la façon la plus simple d'alerter un client avant qu'il soit bloqué.

### Le quota des notifications

Le module de notification pose lui-même une question au module de facturation avant
d'envoyer : « ce client a-t-il encore le droit à ce message ? ». C'est un **port**
(`QuotaGuard`), pas un appel direct.

**Si le quota est dépassé, rien n'est envoyé** et l'appel reçoit `429 quota-exceeded`.
L'envoi à WhatsApp est également **compté** dans le quota du projet : un projet dont le quota
de messages est épuisé n'envoie plus de code de connexion au portail.

---

## 6. Notification, webhook, paiement : les trois à ne pas confondre

| | **Notification** | **Webhook** | **Paiement** |
|---|---|---|---|
| **C'est** | un message adressé à quelqu'un | une livraison HTTP sortante d'un événement d'état | un encaissement auprès d'un prestataire |
| **Qui l'émet** | le module de notification | le module qui a publié l'événement | le module de paiement |
| **Comment** | `POST /v1/notifications/{text,image,file}` | le relais de l'outbox | `POST /v1/payments/*` ou le portail |
| **Identifiant** | l'identifiant de la notification | l'identifiant de l'événement et de la livraison | l'identifiant du paiement |
| **Cycle de vie** | `queued` → `sent` → `delivered` → `read` / `failed` | livraisons, tentatives, file des lettres mortes | les 7 états de paiement |
| **Effet sur l'argent** | aucun | aucun | **le seul** : c'est lui qui écrit au grand livre |

**Une notification n'écrit jamais au grand livre.** Elle consomme un quota, rien de plus.
**Un webhook ne bouge jamais d'argent** : il *raconte* ce qui s'est passé. **Un paiement,
lui, est le seul événement de la chaîne qui modifie le grand livre.**

> **Rien ne déclenche une notification automatiquement.** Les trois endpoints `send-*` sont
> la seule porte d'entrée — même en réaction à un `payment.status_changed`. Si vous voulez
> que votre client soit prévenu par SMS quand son paiement aboutit, **c'est à vous** de
> l'appeler.

---

## 7. Ce que l'exploitant doit en faire

Voici les questions qu'un client vous pose, et où trouver la réponse.

### « D'où vient ce montant ? »

1. Ouvrez la fiche du paiement, ou la fiche de la facture.
2. Pour une facture : l'onglet `« Encaissements de la facture »` donne l'origine de chaque
   règlement.
3. Pour un montant dans une balance : l'écran `« Grand livre et balance »`, filtrez sur le
   type de transaction, ouvrez la ligne — **chaque transaction montre ses lignes débit et
   crédit**.
4. Vous savez alors : de quel compte vers quel compte, sur quel projet, en quelle devise.

### « Pourquoi mon solde a-t-il changé ? »

1. La fiche du portefeuille (`« Écritures → »`) liste **chaque mouvement** : date, type,
   référence, sens, montant, identifiant de la transaction.
2. Chaque ligne porte un `ID transaction`. Ce sont les **mêmes identifiants** que ceux du
   grand livre.
3. Vous suivez cette transaction dans le grand livre : vous voyez les deux comptes
   concernés et le motif.

### « Cette exception bloque-t-elle de l'argent ? »

Regardez **la colonne `« En attente au grand livre »`** de l'écran des exceptions.

- Si elle est **non nulle** : de l'argent est réellement dans le bassin. L'exception doit
  être résolue pour que cet argent soit imputé.
- Si elle est **nulle** : rien n'est bloqué. L'exception est un **constat** (un écart de
  montant, un rapprochement qui ne colle pas), pas une somme en attente.

Et dans le doute : la décision `« Aucun mouvement (rien n'était écrit) »` existe
exactement pour ce cas.

### « Le client a-t-il été bloqué par son quota ? »

1. L'écran `« Usage et quotas »` du projet, onglet `« Limite atteinte »`.
2. La colonne `« Consommation »` montre la jauge.
3. La page `« Utilisation »` du portail client montre la même information au client, avec
   la date de renouvellement.

Si la jauge est pleine mais que le compteur ne repart pas, vérifiez la **fenêtre** : un
compteur à remise à zéro **par période** ne repart qu'à la période suivante.

### « La plateforme est-elle en panne, ou est-ce moi ? »

`GET /ready` répond `200` avec `{"status":"ready"}` quand tout va bien, `503` avec
`{"status":"degraded"}` sinon. Le champ `checks` dit **quel** sous-système ne va pas :

```json
{"status":"ready","checks":{"database":true,"valkey":true,"outbox_relay":true}}
```

Trois contrôles : la base de données, le cache Valkey, et le **relais de l'outbox** (dont la
 ligne la plus ancienne non relayée doit avoir moins de 5 minutes). Le relais est le
témoin le plus utile : s'il est bloqué, les événements s'accumulent et vos webhooks
reçoivent de plus en plus tard.

### « Cette livraison de webhook a-t-elle abouti ? »

La fiche de la livraison donne tout : les tentatives, le code HTTP reçu, la durée, et
**la requête envoyée et la réponse reçue**, ligne par ligne. Si le code est `4xx`, le Core
n'a pas réessayé : la cause est chez vous.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| La transaction et l'invariant de somme nulle | `packages/ledger/src/Domain/Model/LedgerTransaction.php` |
| Le déclencheur d'équilibre en base | `packages/ledger/database/migrations/2026_09_15_000004_create_ledger_entries_table.php` |
| L'immuabilité et l'isolation inter-projets | `packages/ledger/database/migrations/2026_10_24_130000_guard_ledger_project_isolation_and_transaction_immutability.php` |
| La balance | `packages/ledger/src/Infrastructure/Persistence/Query/DatabaseLedgerReadModel.php` |
| Le portefeuille et son verrou | `packages/wallet/src/Domain/Model/Wallet.php`, `…/Application/UseCase/TransferFundsUseCase.php` |
| Les fonds insuffisants | `packages/wallet/src/Domain/Exception/InsufficientFunds.php` |
| Les états d'un paiement | `packages/payment/src/PaymentStatus.php` |
| Le crédit conditionnel d'un portefeuille | `packages/payment/src/Domain/Model/Payment.php` (`shouldCreditWallet`) |
| La boîte d'envoi et son relais | `packages/events/src/Infrastructure/Relay/OutboxRelay.php` |
| Le compteur atomique | `packages/billing/src/Infrastructure/Persistence/Usage/PostgresUsageStore.php` |
| Le quota des notifications | `packages/notification/src/Domain/Port/QuotaGuard.php` |
| Les règles | `docs/adr/0076-grand-livre-complet-plan-de-comptes.md`, `0077-confirmation-des-encaissements-et-exceptions.md`, `0078-rapprochement-et-controles-d-invariants.md`, `0037-credit-wallet-jamais-automatique-composition-explicite.md`, `0036-paiement-confirme-credite-le-wallet-en-synchrone.md`, `0074-usage-et-quotas.md`, `0008-outbox-transactionnel.md` |
