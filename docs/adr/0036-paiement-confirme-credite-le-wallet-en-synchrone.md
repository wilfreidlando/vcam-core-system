# ADR-0036 — Un paiement confirmé crédite le wallet en synchrone, pas via le relais de l'outbox

- **Statut** : Accepté — le *mécanisme* (synchrone, même transaction) reste
  valable ; sa *condition de déclenchement* est corrigée par
  [ADR-0037](0037-credit-wallet-jamais-automatique-composition-explicite.md) :
  seulement sur instruction explicite de l'appelant, jamais pour tout
  paiement confirmé par défaut.
- **Date** : 2026-09-15
- **Dérives concernées** : D2, D6

## Contexte

Le Lot 2 (ledger, wallet, parties — [ADR-0033](0033-conception-lot-2-money-core.md))
et le premier adaptateur provider concret du Lot 3 (MyCoolPay, transport pur)
sont livrés en parallèle par deux agents différents sur ce dépôt. Le graphe de
modules (`tests/Architecture/ModuleGraph.php`) autorise déjà `payment` à
dépendre de `wallet` — cette dépendance a été anticipée dès la Phase 0, avant
qu'aucun des deux modules n'existe. Ce qui n'a jamais été tranché : **par quel
mécanisme** un paiement confirmé traverse cette dépendance pour créditer un
wallet.

`Core\Payment\Contracts\Events\PaymentStatusChanged` existe déjà (publié par
`MyCoolPayWebhookController` dans l'outbox transactionnel, conformément à
ADR-0032). Son docblock affirme : « Consumers (apps, future wallet/audit
/notification modules) subscribe via the existing `EventOutbox` — they do NOT
call back into the adapter for this. » Pris littéralement, cela suggère que le
crédit du wallet attendrait le **relais** de l'outbox (Lot 4 — lecture des
événements en attente, dispatch à des abonnés) : un paiement confirmé écrirait
un événement, et un worker de file, plus tard, le lirait et créditerait le
wallet.

Ce README-là (`packages/events/README.md`, ADR-0032, ADR-0024) est explicite
sur ce que l'outbox couvre aujourd'hui et ce qu'il ne couvre pas : « il n'y a
délibérément aucune méthode "publish now" ou "read pending" ici — cela
appartient au relais (Lot 4), qui n'existe pas encore ». **Le relais n'existe
pas.** Si le crédit du wallet dépend de lui, un paiement confirmé aujourd'hui
ne crédite jamais de wallet — pas « plus tard », jamais, jusqu'à ce que le Lot
4 soit construit. Pour un système dont le Lot 2 a été bâti avec la rigueur
explicite d'un « mini core banking » (triggers PostgreSQL réels, pas seulement
applicatifs), laisser cette question implicite au lieu de la trancher est
exactement le genre de silence que ce projet a choisi de refuser ailleurs.

Il y a aussi un risque d'intégrité financière (D6) propre à l'option
asynchrone, indépendamment du fait que le relais existe ou non : entre le
moment où le paiement est marqué confirmé et le moment où un worker credite le
wallet, le système est dans un état incohérent — le provider dit « payé », le
Core dit « pas encore disponible ». Ce n'est pas nécessairement interdit (les
systèmes de paiement réels vivent avec de l'eventual consistency), mais ce
n'est pas non plus anodin, et ce projet ne l'a jamais choisi explicitement.

## Décision

**Le crédit du wallet suite à un paiement confirmé est un appel PHP
synchrone, à l'intérieur de la même transaction que l'écriture d'état du
paiement et son événement outbox — jamais un effet différé du relais.**

Concrètement, un futur cas d'usage `Core\Payment\Application\UseCase`
(nom provisoire : `HandlePaymentStatusChanged` ou `ConfirmPayment`), appelé
par `MyCoolPayWebhookController` (et par tout futur adaptateur), fait — dans
un seul `UnitOfWork::transactional()` :

1. Écrit/met à jour l'état interne du paiement (table possédée par `Payment`,
   pas encore construite — hors périmètre de cet ADR).
2. **[Corrigé par ADR-0037]** Si le statut canonique est un état de succès
   terminal (`completed` — voir `PaymentStatus`) **ET que l'appelant a
   explicitement demandé un crédit de wallet pour ce paiement** (jamais pour
   tout paiement confirmé par défaut — voir ADR-0037) : résout la Party du
   bénéficiaire via
   `Core\Parties\Contracts\PartyFinder::findOrCreate()` (le paiement fournit
   son propre `external_ref` pour cette party — `payment` dépend déjà de
   `parties` dans le graphe), puis appelle
   `Core\Wallet\Contracts\CreditWallet::credit()` avec une
   `idempotencyKey` **dérivée déterministiquement** de la référence du
   paiement (`transactionRef`/`appTransactionRef`), jamais générée à chaque
   appel — un webhook rejoué ou un `checkStatus()` de réconciliation qui
   retombe sur le même paiement confirmé doit retrouver la même clé et donc
   ne rien créditer une deuxième fois (le mécanisme d'idempotence ledger de
   l'[ADR-0033 §2](0033-conception-lot-2-money-core.md#2-idempotence-à-deux-niveaux-pas-un-seul-mécanisme)
   fait le reste).
3. Publie `PaymentStatusChanged` dans l'outbox (`EventOutbox::append()`),
   inchangé par rapport à ce qui existe déjà.

Si l'appel à `CreditWallet` échoue, toute la transaction — écriture du
paiement comprise — est annulée ; le contrôleur webhook répond en erreur, le
provider réémet le webhook plus tard, et l'idempotence ledger garantit qu'un
rejeu réussi ne crédite toujours qu'une fois.

**Ce que l'outbox reste : la notification, pas la comptabilité.**
`PaymentStatusChanged` continue d'exister et d'être publié — mais pour des
consommateurs **externes** au flux financier critique : le produit appelant
(webhook sortant vers son propre backend), une future Notification (Lot 5),
un Audit. Ces consommateurs tolèrent la latence et la sémantique
« at-least-once » du relais (Lot 4) ; le wallet, lui, ne les tolère pas et n'a
pas à les tolérer, puisqu'il est dans le même processus et peut simplement
être appelé directement, exactement comme `CreditWalletUseCase` appelle déjà
`LedgerPoster::post()` en synchrone plutôt que de passer par un événement.

Ceci n'est pas une violation de **G8** (aucun événement dispatché hors de
l'outbox) : G8 interdit `event()`/`dispatch()` à la place de l'outbox pour ce
qui *est* un événement métier à publier. Un appel de méthode direct vers le
`Contracts/` d'un autre module — ce que `Payment` fait déjà envers `Ledger`
en Lot 2 pour `Wallet`, et ce que `Wallet` fait déjà envers `Ledger` — n'est
pas un événement, c'est la façon normale dont deux modules collaborent selon
**G3c**.

## Alternatives écartées

- **Créditer via le relais de l'outbox (lecture asynchrone du
  `PaymentStatusChanged`, Lot 4)** : c'est l'implication littérale du
  docblock actuel de `PaymentStatusChanged`. Écartée pour deux raisons : (1)
  le relais n'existe pas — adopter ce design aujourd'hui revient à ne
  jamais créditer un wallet avant le Lot 4, un silence non assumé plutôt
  qu'une décision ; (2) même une fois le relais construit, cela introduirait
  une fenêtre d'incohérence financière (D6) sur le chemin le plus critique du
  système, sans bénéfice compensatoire clair — contrairement à la
  notification externe, où l'asynchronisme est un vrai découplage utile
  (le Core ne doit pas attendre que le produit appelant traite son webhook).
- **Un troisième mécanisme dédié (event bus interne, listener Laravel)** :
  aurait dupliqué ce que `Contracts/` + appel direct font déjà, et aurait
  ouvert une nouvelle catégorie de couplage (D2) à gouverner — inutile tant
  qu'un appel de méthode direct suffit à l'intérieur d'un seul processus PHP.

## Conséquences

- Le futur cas d'usage `Payment` qui gère `PaymentStatusChanged` dépend, via
  `Contracts/`, à la fois de `Parties` et de `Wallet` — déjà couvert par le
  graphe existant, aucune modification de `ModuleGraph.php` requise.
- La clé d'idempotence transmise à `CreditWallet::credit()` doit être une
  fonction pure de la référence du paiement, jamais un UUID généré par
  appel — point d'attention explicite pour l'implémentation à venir.
- Le docblock de `Core\Payment\Contracts\Events\PaymentStatusChanged` (« they
  do NOT call back into the adapter for this ») reste vrai pour l'adaptateur,
  mais sa description des consommateurs (« future wallet… modules ») devrait
  être corrigée pour ne plus laisser entendre que le wallet est crédité via
  le relais — à corriger par qui reprend ce fichier, pour ne pas entrer en
  conflit avec le travail en cours dessus au moment de cet ADR.
- Cet ADR ne conçoit pas le cas d'usage `Payment` lui-même (nom exact, table
  d'état, gestion des échecs partiels, remboursements) — seulement le
  mécanisme de communication avec `Wallet`. La conception complète reste un
  travail de Lot 3.
