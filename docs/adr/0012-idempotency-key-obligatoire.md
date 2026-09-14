# ADR-0012 — `Idempotency-Key` obligatoire sur les mutations monétaires

- **Statut** : Accepté (spécification technique v1.0, ADR-12). Design détaillé par [ADR-0025](0025-design-idempotence.md).
- **Date** : 2026-09
- **Dérives concernées** : D6

## Contexte

Le mobile money rend les rejeux fréquents : timeout réseau, redélivrance de
webhook, retry client. Sans idempotence, la deuxième tentative double l'effet —
un double débit n'est pas un bug mineur, c'est un remboursement et une perte de
confiance.

## Décision

Toute mutation monétaire (paiement, versement, crédit/débit de wallet, livraison
de webhook) exige une clé d'idempotence. Rejouer la même requête renvoie le
résultat d'origine sans second effet. Garde-fou **G7**
(`tests/Architecture/IdempotencyTest.php`) : toute route dont l'URI correspond à
un motif de mutation monétaire doit porter le middleware d'idempotence, sinon la
CI échoue.

## Conséquences

Le mécanisme de stockage (clé, hash du corps, réponse mise en cache, TTL) est
figé et détaillé dans [ADR-0025](0025-design-idempotence.md) ; implémenté au
Lot 2 avec les premières mutations de wallet.
