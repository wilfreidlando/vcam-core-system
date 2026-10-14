# ADR-0065 — Opérations de wallet exposées : transferts, retraits enregistrés, historique

- **Statut** : Accepté — remplace ADR-0033 §1 (aucune mutation HTTP de wallet)
- **Date** : 2026-10-14
- **Décideurs** : utilisateur (propriétaire du Core et du premier produit consommateur)
- **Dérives concernées** : D1 périmètre · D5 contrat · D6 intégrité financière

## Contexte

- La spécification fonctionnelle §5.3 range dans le périmètre du Core des wallets
  avec dépôts, retraits, **transferts** et historique complet des mouvements.
  Le Lot 2 n'en a exposé qu'une lecture de solde. ADR-0033 §1 a différé toute
  mutation HTTP « tant qu'aucun appelant réel n'existe », pour ne pas concevoir
  l'autorisation par anticipation.
- Un appelant réel existe maintenant : une plateforme de tourisme. Elle tient
  dans le Core des wallets pour ses clients finaux (remboursements dépensables)
  et pour ses marchands (part des ventes, retirable sur demande), plus des
  wallets de séquestre et de commission.
  - Le produit garde toutes ses règles : commissions, frais, délais, droits.
  - Il demande au Core trois choses : déplacer de la valeur entre wallets,
    enregistrer une sortie, et lire l'historique.
- Le risque qu'ADR-0033 §1 voulait éviter, c'est un **crédit libre** : un
  projet qui crée de la valeur à volonté dans ses propres wallets.
- **Les quatre portes** (gouvernance §4) :
  1. **Transverse** : la capacité est déjà dans la spécification fondatrice ;
     tout produit qui détient des fonds pour ses utilisateurs en a besoin
     (marketplace, réservation, abonnement avec avoir).
  2. **Générique** : aucune règle métier. Le Core ne connaît que des parties
     (`external_ref` libre), des montants, une devise, un libellé et des
     métadonnées opaques.
  3. **Gain supérieur au coût** : sans cela, chaque produit tiendrait ses
     propres soldes, en contradiction avec la raison d'être du ledger.
  4. **Contractualisable** : quatre routes et deux événements existants,
     enrichis sans rupture.

## Décision

1. **`POST /v1/wallets/transfers`** (`wallets:write`, `Idempotency-Key`,
   garde-fou G7) :
   - de **1 à 20 jambes** `{from, to, amount}` entre parties du même projet,
     dans **une seule transaction ledger** : tout passe ou rien ne passe ;
   - `description` et `metadata` (objet JSON opaque, 4 Ko au plus) sont libres ;
   - le wallet d'un destinataire est créé au premier crédit (création paresseuse,
     spec §4.3), celui d'une source doit exister ;
   - les soldes sont verrouillés **dans l'ordre des identifiants**, pour éviter
     les interblocages (ADR-0006) ;
   - un solde insuffisant donne `409 insufficient-funds`, sans aucun mouvement.
   - Un transfert est **à somme nulle** : il ne crée aucune valeur, ce qui
     répond à la préoccupation d'ADR-0033 §1.
2. **`POST /v1/wallets/withdrawals`** (`wallets:write`, `Idempotency-Key`) :
   - enregistre qu'une valeur **sort** de l'écosystème (versement fait hors du
     Core, preuve dans `external_reference`) ;
   - écriture : débit du wallet, crédit du compte `withdrawal` du projet ;
   - une sortie ne crée pas de valeur non plus ;
   - le versement automatique vers un numéro (payout provider) fera l'objet d'une
     décision distincte quand un adaptateur le permettra.
3. **Aucun crédit libre n'est exposé.** La seule entrée de valeur dans un wallet
   reste un paiement confirmé, avec `wallet_recipient_external_ref` (ADR-0037).
4. **Lecture** (`wallets:read`) :
   - `GET /v1/parties/{externalRef}/wallets` : wallets et soldes d'une partie,
     sans jamais créer la partie (nouveau contrat `Parties\Contracts\PartyLookup`) ;
   - `GET /v1/wallets/{walletId}/transactions`, paginé, du plus récent au plus
     ancien : chaque mouvement porte son sens, son montant, son type
     (`payment`, `transfer`, `withdrawal`), l'opération, sa `reference` (la clé
     d'idempotence de l'appelant), sa description et ses métadonnées.
   - La source de vérité reste le ledger. La table `wallet_operations` du module
     `wallet` ne garde que ce que le ledger ne porte pas (clé de l'appelant,
     description, métadonnées, jambes), reliée par
     `ledger_transactions.external_ref`.
5. **Événements.** `wallet.credited` et `wallet.debited` gagnent, sans rupture, les
   champs `party_external_ref`, `operation_id`, `operation_type`, `reference`
   et `description` quand ils sont connus. Un transfert émet un événement par
   wallet dont le solde change.
6. **Scope `wallets:write`** ajouté à `ApiKeyScopes::ALL`. Une migration
   l'ajoute aux clés existantes qui portent déjà `wallets:read`.

## Alternatives écartées

- **Garder ADR-0033 §1 et laisser chaque produit tenir ses soldes** : duplique le
  ledger dans chaque produit et contredit la spec §5.3. Le premier produit
  consommateur a explicitement refusé de construire des wallets.
- **Exposer un `credit`/`debit` unitaire générique** : le crédit libre est
  précisément le risque de fraude d'ADR-0033 §1. Le transfert à somme nulle et
  la sortie enregistrée couvrent les besoins sans créer de valeur.
- **Des transferts unitaires enchaînés par le produit** : une répartition (part
  du marchand + commission) pourrait s'arrêter à moitié. La transaction
  multi-jambes est atomique.
- **Holds (`held_minor`) pour le séquestre** : un wallet de séquestre détenu par
  le produit, alimenté puis vidé par transferts, couvre le besoin sans nouvel
  état. Les holds restent possibles plus tard.

## Conséquences

- Migrations : table `wallet_operations`, scope `wallets:write` sur les clés
  existantes.
- `CreditWalletCommand` reçoit `partyExternalRef` (facultatif), repris dans
  l'événement.
- Tests : parcours HTTP complet, isolation par projet, concurrence réelle sur
  PostgreSQL (transferts croisés en processus séparés).
- Documentation intégrateur : section « Wallets » du guide `curl`, catalogue des
  événements enrichi, OpenAPI régénéré.
- **Réévaluation** : un deuxième produit qui demanderait des holds, un payout
  provider ou des règles de frais calculées par le Core rouvrira ces points par
  une nouvelle ADR.
