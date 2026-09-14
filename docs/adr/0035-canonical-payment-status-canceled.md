# ADR-0035 — Étendre le vocabulaire canonique `PaymentStatus` avec `canceled`

- **Statut** : Accepté — étend [G6b](../adr/0006-… non applicable ; voir `CanonicalStatusTest`](../../../tests/Architecture/CanonicalStatusTest.php))
- **Date** : 2026-09-15
- **Dérives concernées** : D3 (vocabulaire provider dans les couches hautes)

## Contexte

Le vocabulaire canonique des statuts de paiement a été posé dans
`tests/Architecture/CanonicalStatusTest.php` :

```
'pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded'
```

Ce vocabulaire était dérivé par anticipation. Le premier provider
concret intégré (My-CoolPay) introduit un statut
`transaction_status: CANCELED` qui ne correspond à aucun des six
canoniques. Les deux candidats les plus proches sont :

- `expired` (fenêtre temporelle fermée) — différent : `canceled`
  est user-initiated (le client annule sur la page de paiement),
  `expired` est system-initiated (le timer de session est tombé).
- `failed` (échec définitif) — différent : un paiement annulé n'est
  pas un échec ; le client a explicitement renoncé.

## Décision

Le vocabulaire canonique devient :

```
'pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled'
```

`canceled` est une valeur distincte de `failed` et de `expired`. Sa
sémantique : « le client ou le système a explicitement annulé la
transaction avant son dénouement ». Elle est adjacente à `failed`
dans le cycle de vie mais doit pouvoir être distinguée pour :

1. Le reporting financier — un paiement annulé n'est pas un échec,
   il ne dégrade pas le taux de conversion.
2. La réconciliation — un webhook `CANCELED` ne doit pas déclencher
   les mêmes workflows qu'un `FAILED` (pas de retry, pas de
   notification de service client).
3. La transparence aux consommateurs — les apps métier ont besoin de
   distinguer les annulations des échecs pour adapter l'UX (le client
   peut réessayer après une annulation, pas après un échec).

`CanonicalStatusTest` est mis à jour en cohérence. G6b reste actif.

## Conséquences

- Le test canonique `tests/Architecture/CanonicalStatusTest.php`
  accepte maintenant `canceled` dans la liste de référence de
  `PaymentStatus`.
- Les modules métier (Payment, Wallet, Ledger) peuvent compter sur
  `canceled` comme état terminal valide.
- Les adapters qui exposent un statut `canceled` le mappent via leur
  `StatusMapper` vers `PaymentStatus::Canceled` sans perte.
- Les adapters qui n'exposent pas `canceled` (MTN MoMo, etc.) ne
  sont pas obligés de le supporter — leur `StatusMapper` lèvera
  `UnknownProviderStatusException` si jamais la raw value apparaît.

## Alternatives écartées

- **Mapper `CANCELED → expired` dans l'adapter MyCoolPay.** Lossy :
  on perd la distinction entre annulation et expiration. Une telle
  perte ne se justifie pas quand un cas réel force la décision.
- **Mapper `CANCELED → failed`.** Plus lossy encore, car le reporting
  financier se trompe. Rejeté pour la même raison.
- **Refuser d'intégrer MyCoolPay tant que le canonique n'est pas étendu.**
  Reculer n'apporte rien — la distinction `canceled` vs `expired` est
  sémantiquement valide et a vocation à être canonique.
