# ADR-0079 — Les versements sortants (payout) : écrire d'abord, verser ensuite, confirmer par trois voies

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D1 périmètre · D4 provider · D6 intégrité financière
- **Nouveau module** : `payout` (graphe : `support`, `identity`, `ledger`, `wallet`, `parties`, `events`, `payment`)
- **Complète** : ADR-0065 (les sorties **enregistrées** restent pour l'argent versé hors plateforme)

## Contexte

Le core sait **enregistrer** qu'une somme est sortie d'un wallet
(`POST /v1/wallets/withdrawals`, ADR-0065) : le virement est fait ailleurs, à
la main, et le core n'en garde que la preuve. Il ne sait pas **verser**.

Un projet SaaS qui reverse de l'argent (à des partenaires, des prestataires,
ses propres clients) doit le faire hors plateforme, avec double saisie et
référence recopiée — source d'erreurs et de fraude interne.

Vérifié : le contrôleur de callback du module `payment` **ignore** les
transactions qui ne sont pas des encaissements ; aucun chemin ne pourrait
aujourd'hui confirmer un versement.

## Décision

### 1. Un module dédié, un port dédié

- Module `payout`, distinct de `payment` (cycle de vie, responsable et statuts
  différents) et de `wallet` (qui garde et fait circuler, sans parler à
  l'extérieur).
- Port `Contracts\PayoutGateway` : `initiate(PayoutRequest)`,
  `checkStatus(ref, projectId)`. Adaptateurs comme les autres (G6, G6c).
- `payment` publie `Contracts\ResolveProviderApplication` : l'application
  prestataire **du projet appelant** pour un prestataire donné. `payout` ne
  touche jamais le port de domaine de `payment`.

### 2. Machine d'états canonique

`pending_approval → pending → processing → succeeded | failed | canceled`,
et `succeeded → reversed` (annulation par le prestataire). `pending_approval`
n'existe que pour un versement soumis à double validation (§6) ; il peut aussi
finir `canceled` (refus de l'approbateur, contre-passation immédiate). Protégée
par le garde-fou de statuts canoniques (G6b étendu à `PayoutStatus`).

### 3. Écrire d'abord

```
BEGIN
  verrou du wallet (ADR-0006) ; solde disponible ≥ montant, sinon 409 insufficient-funds
  défense en profondeur : solde clearing:<p> du projet ≥ montant, sinon 409 insufficient-provider-balance
  Débit wallet:<partie> / Crédit payout_in_transit:<p>
  payout (pending) + outbox
COMMIT
appel au prestataire (hors transaction) → processing | failed
```

- `failed` : contre-passation (Débit `payout_in_transit` / Crédit `wallet`),
  jamais de suppression.
- `succeeded` : Débit `payout_in_transit:<p>` / Crédit `clearing:<p>`.
- `reversed` : Débit `clearing:<p>` / Crédit `wallet:<partie>`.

### 4. Trois voies de confirmation

| Voie | Mécanisme |
|---|---|
| Callback | contrôleur dédié du module `payout`, même authentification que les callbacks d'encaissement (IP + signature, ADR-0051) |
| Consultation | `GET /v1/payouts/{id}` et le balayage appellent `checkStatus()` et convergent vers la même machine d'états |
| Balayage | job toutes les minutes pour les versements `processing` depuis plus de N minutes (équivalent d'ADR-0067) |

**Résultat ambigu** (expiration du délai après l'appel) : le versement reste
`pending`, **sans** contre-passation automatique, jusqu'à confirmation par
consultation ou balayage — compenser alors que l'argent part peut-être serait
une double sortie. `initiate` n'est **jamais** rejoué sans consultation
préalable du statut.

### 5. Le versement part du compte du projet, et d'aucun autre

La route résout l'application prestataire du **projet appelant** ; sans
application → `422 provider-application-missing`. Aucun repli sur une
application de plateforme. Le trigger inter-projets du grand livre (ADR-0076
§5) rend tout mouvement croisé impossible.

### 6. Contrôles anti-fraude configurables par projet

- Plafond par versement, plafond quotidien par projet et par bénéficiaire.
- **Double validation** au-delà d'un seuil : un versement au-dessus du seuil
  reste `pending_approval` jusqu'à l'approbation d'un second compte console ;
  l'auteur ne peut pas s'approuver (contrainte en base).
- Bénéficiaire = une `Party` du projet ; le numéro de destination est **chiffré**
  au repos et n'est affiché que masqué (ADR-0083).
- Premier versement vers un nouveau numéro : délai de sécurité optionnel
  (`payout_settings.new_destination_hold_minutes`).

### 7. API et événements

`POST /v1/payouts` (`payouts:write`, `Idempotency-Key`, portée
`(project_id, clé)`), `GET /v1/payouts/{id}`, `GET /v1/payouts` (`payouts:read`).
Console : création manuelle (motif obligatoire), approbation. Événement
`payout.status_changed`.

## Alternatives écartées

- **Étendre `PaymentGateway`.** Rejeté : deux cycles de vie mélangés dans un
  port et une machine d'états.
- **Appeler le prestataire d'abord, écrire ensuite.** Rejeté : un plantage
  entre les deux laisse un versement parti sans trace.
- **Rejouer `initiate` sur erreur.** Rejeté : double versement si le premier
  appel avait abouti.
- **Un compte de plateforme pour verser au nom des projets.** Rejeté :
  mélange des fonds entre projets, impossible à rapprocher par projet.

## Conséquences

- Nouveau module, nouvelle arête `admin → payout`, scopes `payouts:read/write`.
- Un adaptateur de versement avec environnement de test du prestataire est
  requis avant la mise en service.
- `payout_in_transit` entre dans le rapprochement de solde (ADR-0078 §4).

## Vérification

- Test : expiration du délai après appel → reste `pending`, aucune
  contre-passation ; la consultation tranche.
- Test : callback rejoué → une seule transition.
- Test : versement au-dessus du seuil approuvé par son auteur → refusé.
- Test : solde insuffisant → `409`, aucun appel au prestataire.
