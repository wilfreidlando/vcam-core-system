# ADR-0121 — Les corrections que la relecture du lot des quotas a apportées

- **Statut** : Accepté
- **Date** : 2026-11-05
- **Décideurs** : commanditaire (la durée de vie d'un supplément), comité d'architecture
- **Dérives concernées** : D1 revenu · D5 contrat · D6 intégrité financière
- **Amende** : ADR-0117 §2 et §3, ADR-0119 §2 et §6
- **Précise** : ADR-0073 §1 et §3, ADR-0074 §1, ADR-0094 §12, ADR-0119 §7

## Contexte

Le lot des quotas (ADR-0117, ADR-0119) a été relu avant d'être livré. Quatre défauts
réels et une ambiguïté commerciale en sont sortis. Un seul décideur pouvait trancher
l'ambiguïté : la durée de vie d'un supplément.

**1. Un supplément restait `pending` à jamais.** L'activateur est branché sur le règlement
et sur l'annulation d'une facture, pas sur sa mise en irrécouvrable (ADR-0073 §3). Une
facture passée en bad debt ne peut plus être ni annulée ni payée : le supplément restait
`pending`, la facture ne pouvait plus jamais être soldée, et le portail — qui retire de
son offre le compteur qui a un rechargement en attente (ADR-0119 §4) — ne pouvait plus
jamais proposer un pack sur ce compteur. Aucun geste opérateur ne levait le blocage.

**2. Un pack couvrait l'éternité.** La limite effective additionnait tous les
suppléments `active` de l'abonnement, sans borne de période : un pack payé une fois
(25 000 XAF) relevait le quota de **tous** les mois suivants. Le manuel, lui, parle d'un
quota « 500 messages par mois » et d'un pack acheté « au milieu du mois » (chapitre 11 du
manuel).

**3. Un pack à prix nul était proposé et ne pouvait pas être vendu.** ADR-0119 §2
autorisait un prix nul ; l'achat, lui, était refusé (le grand livre refuse une ligne à
zéro, ADR-0073 §1). Le portail affichait donc « 0 XAF » et un bouton « Recharger » qui
échouait à chaque fois, sans que l'opérateur voie jamais pourquoi.

**4. Une clé d'idempotence ne disait pas quel geste elle protégeait.** Dans la console,
la clé était le nonce du formulaire : le navigateur qui restitue la page après un
« retour » rejouait la même clé, et le pack choisi entre-temps était remplacé par
l'achat déjà fait — la console annonçait un pack accordé qui ne l'était pas.

## Décision

### 1. La mise en irrouvrable annule le supplément

`MarkInvoiceUncollectibleUseCase` appelle l'activateur, comme
`VoidInvoiceUseCase`. Un supplément passe donc de `pending` à `canceled` : il n'a rien
accordé, et le compteur redevient achetable dans le portail.

### 2. Un supplément couvre la période payée

**La période en cours** — décision du commanditaire.

La limite effective n'est plus la somme des suppléments actifs de l'abonnement : c'est la
somme des suppléments **activés pendant la période en cours**. Un pack acheté en
novembre est cinquante messages de plus en novembre ; en décembre, la limite est celle du
plan.

L'allocation suit la fenêtre du compteur : un compteur qui se remet à zéro avec la
période prend l'allocation de la période ; un compteur qui ne se remet jamais
(ADR-0071 §3) prend l'allocation du contrat entier.

Un règlement arrivé après la fin de la période ne donne pas d'allocation perdue : le
supplément s'active dans la période où l'argent est arrivé, et c'est cette période-là qui
le porte. Rien n'est pris sans rien donner.

### 3. Un pack porte un prix strictement positif

ADR-0119 §2 est amendé : `unit_price_minor` est **strictement positif**. Le catalogue le
refuse à la déclaration (le domaine, pas le schéma), et le portail ne propose pas un
pack à prix nul — un catalogue déclaré avant la règle ne ressuscite pas une offre morte.

### 4. La clé d'un achat dit quel geste

La clé dit quel geste elle protège. Dans la **console**, elle porte le contrat, le pack et
le nonce du formulaire ; dans le **portail**, elle porte la session, le pack et le nonce
(ADR-0119 §6). Deux packs différents ont deux clés différentes, et le rejeu ne rend que le
résultat du **même** geste : si la clé porte déjà un autre achat, l'achat est refusé.

## Alternatives écartées

- **Un supplément éternel.** Écarté par le commanditaire : un paiement unique ne peut pas
  acheter un quota à vie.
- **Un solde cumulé** (les unités achetées se consomment quand le client veut, sur
  plusieurs périodes). Écarté : c'est un autre modèle — le compteur de période puiserait
  dans un solde, et non plus porter une limite relevée.
- **Un pack offert.** Écarté : il faudrait un cas d'usage « accorder » qui n'existe pas, et
  le grand livre refuse une facture de zéro.

## Conséquences

- ADR-0117 §3 est amendé sur la somme : elle porte la période en cours, pas le contrat.
- La clause « la limite effective ne peut qu'augmenter » d'ADR-0117 vaut **à l'intérieur
  d'une fenêtre** : au changement de période, la limite revient à celle du plan. La
  sécurité en concurrence (une admission qui lit une valeur périmée est au plus plus
  stricte) est inchangée : elle porte sur le compteur de la fenêtre.
- `subscription_supplements.period_id` reste la période de l'achat ; la période qui
  porte l'allocation est celle de l'activation.
- La console et le portail ne peuvent plus annoncer un achat qui n'a pas eu lieu.

## Vérification

- Test d'intégration (`packages/billing/tests/Integration/SupplementFlowTest.php`) : un
  pack payé dans la période relève la limite de cette période et **pas** celle de la
  suivante ; une facture mise en irrouvrable annule le supplément qu'elle portait.
- Test d'intégration (`packages/billing/tests/Integration/SupplementPackCatalogueTest.php`) :
  un pack à prix nul est refusé ; une clé qui porte déjà un autre achat est refusée.
- Non-régression : la suite `billing` et la suite `portal` restent vertes.
