# ADR-0094 — Lot 11 : la facture et l'encaissement

- **Statut** : Accepté
- **Date** : 2026-10-27
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27), sur proposition de
  l'implémentation (reconnaissance du lot 11)
- **Amende** : ADR-0072 (abonnements), ADR-0073 (factures), ADR-0076 (grand livre),
  ADR-0083 (données personnelles), ADR-0092 (lot 10)
- **Dérives concernées** : D3 données/PII · D5 contrat · D6 intégrité financière

## Contexte

ADR-0073 fixe le modèle de la facture et de son encaissement, mais laisse
plusieurs points **indéterminés** que le code livré doit trancher avant de
pouvoir écrire une facture :

- la taxe est prescrite « arrondie au demi supérieur **par ligne** », mais le
  DDL ne stocke que le **taux** : le montant arrondi n'a nulle part où être
  écrit, et `total = subtotal + tax` devient invérifiable ;
- la numérotation est « attribuée à l'émission sous verrou de ligne », mais
  le format du numéro (année, remplissage) n'est écrit nulle part ;
- ADR-0072 veut une facture de renouvellement **déjà ouverte**, ADR-0073 veut
  le numéro attribué **à l'émission** ;
- ADR-0092 §2 dit que la déclaration de paiement « ne bouge pas d'argent » —
  vrai au lot 10, faux au lot 11, où l'argent a son propre chemin ;
- ADR-0076 §3 promet un `purpose` de paiement : le code livré ne l'a pas, et
  un paiement confirmé d'une facture est donc crédité `revenue` — la recette
  comptée deux fois ;
- ADR-0083 §6 ordonne d'effacer `invoices.customer_snapshot` à l'effacement
  d'un client, alors qu'ADR-0073 §1 gèle la facture à l'émission.

Un ADR accepté ne se réécrit pas : les points ci-dessus sont consignés ici,
et les ADR cités sont amendés, pas réécrits.

## Décision

### 1. La taxe est stockée **par ligne** (amende ADR-0073 §1)

`invoice_lines` porte `tax_amount_minor bigint NOT NULL DEFAULT 0`, et la
base en vérifie la valeur :

```sql
CHECK (tax_amount_minor = (amount_minor * tax_rate_bps + 5000) / 10000)
```

L'arithmétique est entière : `(x + 5000) / 10000` est l'arrondi au demi
supérieur exact, sans flottant (G5). La taxe de la facture est la **somme des
taxes de ses lignes**, jamais un taux appliqué au sous-total : deux lignes à
deux taux différents donnent deux résultats différents, et le Core calcule
ce qu'on lui donne (ADR-0073 §1).

### 2. Le snapshot du client reste un `jsonb` chiffré (précise ADR-0073 §1, ADR-0083 §1/§6)

`invoices.customer_snapshot` est le **nom** de colonne qu'ADR-0083 §1 et §6
nomment, et ADR-0073 §1 le spécifie. Ce qui change, c'est son contenu : les
valeurs personnelles y sont **chiffrées** avec le chiffreur versionné
d'ADR-0083 §2, exactement comme sur la ligne `customers`.

Le snapshot porte donc :

```json
{
  "external_ref": "CUST-00042",
  "display_name_ciphertext": "pii:v1:…",
  "email_ciphertext": "pii:v1:…",
  "phone_ciphertext": null,
  "locale": "fr"
}
```

`external_ref` est la clé opaque du projet (ADR-0070 §1) : ce n'est pas une
donnée personnelle. Le reste est chiffré, et **jamais** rendu en clair par la
console (ADR-0093 §6).

### 3. La facture porte un verrou optimiste

`invoices.version integer NOT NULL DEFAULT 1` et l'écriture porte la version
attendue dans son `WHERE` — la discipline du client (ADR-0093 §8) et de
l'abonnement. Sans elle, deux imputations concurrentes liraient
`amount_paid_minor = 0` et s'écraseraient : l'invariant « Σ créances = Σ
(total − payé) » deviendrait faux.

### 4. Les réglages de facture arrivent dans `billing_settings` (amende ADR-0092 §4)

| Réglage | Défaut | Rôle |
| --- | --- | --- |
| `invoice_prefix` | `'FACT'` | Préfixe du numéro, `^[A-Z0-9]{2,12}$` |
| `default_tax_rate_bps` | `0` | Taux par défaut des lignes manuelles |
| `manual_receipt_approval_threshold_minor` | `0` | Au-dessus, l'encaissement hors ligne exige un second compte |
| `retain_invoice_identity` | `true` | Conservation de l'identité figée sur les factures à l'effacement |
| `document_branding` | `{}` | Nom, logo et mentions libres des documents |

ADR-0092 §4 annonçait ces réglages « avec les ADR qui les introduisent » :
c'est cet ADR-là.

### 5. Le numéro d'une facture

`<prefix>-<année>-<séquence>`, la **séquence sur six chiffres** (`FACT-2026-000042`),
l'année étant celle de l'**émission**, dans le fuseau du projet.

La séquence est consommée sous `SELECT … FOR UPDATE` sur
`(project_id, year)` : **sans trou**, par projet et par année. Une séquence
PostgreSQL laisserait un trou à chaque rollback (ADR-0073 §Alternatives).

### 6. La facture de renouvellement est créée puis émise dans la même transaction (amende ADR-0072 §4)

ADR-0072 §4 veut une facture « ouverte, échéance = `ends_at` » à
`ends_at − renewal_lead_days`. ADR-0073 veut le numéro à l'émission. Les
deux se satisfont : la facture est créée `draft` **et** émise dans la
transaction qui prépare le renouvellement. L'observateur voit une facture
ouverte ; le numéro est attribué à l'émission.

`subscription_periods.invoice_id` est renseigné à l'émission, et pointe sur
la facture **vivante** : une facture annulée puis réémise réécrit la colonne.

### 7. L'effacement d'un client et le snapshot figé (précise ADR-0083 §6)

L'effacement vide la fiche client (Lot 10) et les factures **brouillon** du
client. Une facture **émise** est un document : son snapshot est gelé
(ADR-0073 §1) et survit à l'effacement — la créance subsiste, le grand livre
reste équilibré (ADR-0083 §4).

`retain_invoice_identity` décide du sort des factures **brouillon** : `true`
(le défaut, conforme à la spécification technique) les conserve, `false` les
vide à l'effacement.

L'option n'ouvre **pas** la porte à une réécriture d'une facture émise :
l'immuabilité d'ADR-0073 §1 est la garantie que le Core tient ; un
document qui change après émission n'est pas un document.

### 8. La déclaration de paiement ne devient pas un chemin d'argent (amende ADR-0092 §2)

ADR-0092 §2 était juste au lot 10, où aucune facture n'existait. Au lot 11 :

- `POST /v1/invoices/{id}/pay` (en ligne, `Idempotency-Key` obligatoire) ;
- l'encaissement hors ligne (console, quatre vues au-delà du seuil) ;
- `POST /v1/invoices/{id}/pay` d'une facture déjà soldée : refusé `409`.

`ConfirmSubscriptionPeriodPayment` **refuse** désormais de déclarer réglée une
période dont la facture n'est pas soldée. Elle ne bouge plus d'argent : elle
constate, et l'acte d'argent est un encaissement. L'API publique ne change
pas — c'est le comportement qui change, et il est plus strict.

### 9. Le `purpose` du paiement existe enfin (amende ADR-0076 §3)

`payment` porte un `purpose` — `direct` (défaut, le comportement actuel),
`wallet_credit`, `invoice`. Le règlement d'un paiement `invoice` est écrit
**Débit `clearing:<prestataire>` / Crédit `collections`** — par `payment`,
seul module qui connaît le compte du prestataire — et `billing` écrit ensuite
**Débit `collections` / Crédit `customer_receivable:<client>`**.

Le paramètre est **optionnel** et son défaut est le comportement actuel :
aucun contrat publié n'est rompu (ADR-0086).

### 10. Un règlement partiel n'est jamais imputé automatiquement (précise ADR-0073 §3)

`amount_paid_minor` n'augmente que d'imputations **totales**. Un écart de
montant part en `payment_exceptions` et s'ouvre comme exception d'argent
(ADR-0077) : rien n'est silencieusement perdu, rien n'est imputé de travers.

### 11. Le reçu est un document dérivé

Aucun reçu n'est stocké : il est rendu depuis la facture et les encaissements
qui lui sont imputés (montant, date, référence du prestataire ou de
l'encaissement hors ligne, facture concernée). Un reçu est une **projection**,
pas un enregistrement.

### 12. L'irrécouvrable est un acte humain

Aucun déclencheur automatique ne fait basculer une facture en
`uncollectible` : c'est une décision explicite, auditée, de l'exploitant. Le
retard reste le `past_due` de l'abonnement (ADR-0072 §2).

### 13. L'encaissement hors ligne ne devient un fait comptable qu'approuvé

Au-dessus du seuil (`manual_receipt_approval_threshold_minor`), l'encaissement
est saisi **en attente** et ne devient un fait comptable qu'à l'approbation
d'un second compte — l'auteur ne peut pas s'approuver (contrainte en base).
Sous le seuil, il est comptabilisé à la saisie.

### 14. Le PDF attendra la bibliothèque

ADR-0073 §5 demande « HTML imprimable ; PDF généré à la demande, non
stocké ». Aucune bibliothèque de PDF n'est dans `composer.json` : le lot livre
le **HTML imprimable** et choisit la bibliothèque PDF dans un ADR dédié. Le
Core ne dépend pas d'une bibliothèque de rendu pour un document qu'il rend
déjà.

## Alternatives écartées

- **Recalculer la taxe sur le sous-total.** Rejeté : l'arrondi est prescrit
  par ligne ; deux lignes à deux taux différents donnent deux résultats, et
  `total = subtotal + tax` deviendrait invérifiable.
- **Des colonnes `*_ciphertext` séparées pour le snapshot.** Rejeté : ADR-0073
  §1 et ADR-0083 §1/§6 nomment `invoices.customer_snapshot`. Les valeurs y
  sont chiffrées, ce qui est ce qu'ADR-0083 §2 exige.
- **Un registre ouvert pour `invoice_lines.kind`.** Rejeté pour ce lot : le
  registre s'ouvre quand `usage` arrive (ADR-0090), et la migration qui
  l'ouvre est un changement de contrainte explicite, tracé. D'ici là, une
  faute de frappe dans le champ qui décide de la nature comptable d'une ligne
  est refusée par la base.
- **Une séquence PostgreSQL pour la numérotation.** Rejeté : elle laisse un
  trou à chaque rollback (ADR-0073 §Alternatives).
- **Un déclencheur automatique d'irrécouvrabilité.** Rejeté : l'effacement
  d'une créance est un acte comptable, pas un effet du temps.
- **Imputer un règlement partiel sur la facture.** Rejeté : ADR-0077 refuse
  l'imputation automatique d'un montant qui ne correspond pas.

## Conséquences

- `billing` dépend de `payment` : le manifeste Composer du module le
  déclare (le graphe l'autorise déjà).
- La matrice d'ADR-0073 §3 est vraie : chaque somme d'un paiement de facture
  est écrite, par le module qui la connaît, et une seule fois.
- `subscription_periods.invoice_id` cesse d'être nul (ADR-0092 §1).
- Les factures deviennent une **liste blanche de colonnes** testée, comme
  `customers` (G4b).
- L'invariant « Σ `customer_receivable` = Σ (total − payé) des factures
  ouvertes, par projet et devise » est vérifié par un test.

## Vérification

- Test : la facture émise est figée — une ligne ajoutée, un montant changé ou
  une échéance déplacée sont refusés par la base.
- Test : deux émissions concurrentes donnent deux numéros **consécutifs**, sans
  trou ni doublon.
- Test : la séquence reprend à 1 chaque année, par projet.
- Test : l'écriture d'émission est équilibrée et porte la clé
  `invoice:<id>:issue` ; la contre-passation d'annulation la reprend.
- Test : une facture payée ne peut plus être annulée.
- Test : le snapshot d'une facture émise ne contient aucune valeur personnelle
  en clair.
- Test : l'invariant des créances tient après une émission et une annulation.
