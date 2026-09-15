# ADR-0037 — Le crédit du wallet suite à un paiement n'est jamais automatique : composition explicite, pas comportement par défaut

- **Statut** : Accepté — amende [ADR-0036](0036-paiement-confirme-credite-le-wallet-en-synchrone.md)
- **Date** : 2026-09-15
- **Dérives concernées** : D1, D2

## Contexte

[ADR-0036](0036-paiement-confirme-credite-le-wallet-en-synchrone.md) tranchait
*comment* un paiement confirmé crédite un wallet (appel synchrone,
même transaction), mais laissait implicite une hypothèse plus lourde : que
*tout* paiement confirmé crédite *automatiquement* un wallet. Correction
explicite du demandeur : ce n'est pas ce qui est voulu.

L'adaptateur MyCoolPay — et les adaptateurs de paiement en général — existent
pour servir une capacité générique : **l'encaissement**. Un « app métier » qui
intègre `Payment` peut vouloir uniquement collecter un paiement (une
commande, un abonnement, des frais ponctuels) sans que l'argent doive
transiter par un wallet Core pour qui que ce soit. « On peut avoir un user
système qui encaisse » — l'argent reçu appartient au système/à la
plateforme, pas nécessairement à un wallet nominatif d'un participant. Le
mécanisme qui, lors d'un dépôt, crédite le wallet *de celui qui dépose*
n'existe pas encore, et **doit être une option**, pas un comportement
imposé.

Faire de « paiement confirmé → wallet crédité » un effet automatique et
inconditionnel à l'intérieur du flux de confirmation de `Payment` violerait
directement la porte n°2 du garde-fou D1 (généricité, §4 de
`docs/specs/gouvernance-anti-derive.md`) : « Elle ne contient aucune règle
métier spécifique à un produit ». « Tout paiement est un dépôt vers un
wallet » *est* une règle métier — vraie pour certains produits (une appli de
tourisme dont les prestataires accumulent des commissions dans un wallet),
fausse pour d'autres (une appli qui vend un produit ponctuel et n'a jamais
besoin qu'un wallet existe). Imposer l'hypothèse au niveau du Core couplerait
deux capacités — encaissement et wallet — qui doivent rester adoptables
indépendamment l'une de l'autre.

## Décision

**Par défaut, un paiement confirmé ne touche ni `Parties` ni `Wallet`.**
La confirmation d'un paiement (traitement de `PaymentStatusChanged` atteignant
un statut canonique terminal de succès) se limite, sauf instruction
contraire explicite, à :

1. Écrire l'état interne du paiement (encaissement pur, au nom du
   système/de la plateforme).
2. Publier `PaymentStatusChanged` dans l'outbox — inchangé.

**Créditer un wallet à partir d'un paiement devient une composition
explicite, demandée par l'appelant au moment où le paiement est initié —
jamais déduite après coup du seul fait que le paiement a réussi.**
Concrètement (mécanisme précis laissé à la conception complète du Lot 3, hors
périmètre de cet ADR) : le futur cas d'usage d'orchestration `Payment` (celui
qui appelle le `PaymentGateway`, distinct du DTO de transport
`InitiatePaymentCommand` qui ne porte que ce dont un provider a besoin)
accepte une instruction de crédit optionnelle — par exemple une référence de
Party (`external_ref`) et une devise. **Seule sa présence** déclenche, à la
confirmation, la résolution de la Party (`Core\Parties\Contracts\PartyFinder`)
puis l'appel synchrone à `Core\Wallet\Contracts\CreditWallet::credit()` décrit
par l'ADR-0036 — ce mécanisme-là (synchrone, même transaction, idempotence
dérivée de la référence du paiement) reste correct et n'est pas remis en
cause, seulement sa déclenche condition.

Sans cette instruction, un paiement reste un encaissement pur : aucune Party
n'est créée, aucun wallet n'est touché, `Payment` fonctionne seul — une
appli métier peut consommer uniquement `Payment` sans jamais provisionner
`Parties`, `Wallet` ou `Ledger`.

## Alternatives écartées

- **Crédit automatique pour tout paiement confirmé** (la lecture littérale
  d'ADR-0036) : écartée — couple deux capacités indépendamment utiles, viole
  la porte de généricité (D1), force la machinerie wallet sur des apps qui
  n'en ont pas besoin.
- **Un indicateur au niveau du Project** (« les paiements de ce projet
  créditent toujours un wallet ») : écartée — un même projet peut légitimement
  vouloir un encaissement ponctuel pour certains paiements (un achat) et un
  dépôt vers un wallet pour d'autres (un top-up) ; un interrupteur global ne
  peut pas exprimer cette distinction, une instruction par paiement le peut.

## Conséquences

- Le futur cas d'usage d'orchestration `Payment` (pas encore conçu) porte sa
  propre commande, distincte d'`InitiatePaymentCommand` (DTO de transport
  provider), avec un champ d'instruction de crédit optionnel.
- `ADR-0036` reste valable pour le *mécanisme* (synchrone, même transaction,
  idempotence par référence de paiement) mais plus pour sa *condition de
  déclenchement*, corrigée ici : sur demande explicite, pas par défaut.
- Aucun fichier de `packages/payment` n'est modifié par cet ADR — un autre
  agent y travaille activement au moment de la rédaction ; ce document sert
  de référence pour la conception complète du cas d'usage au Lot 3.
