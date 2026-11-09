# ADR-0120 — La clé d'une notification Core identifie la ressource, pas seulement la fenêtre

- **Statut** : Accepté
- **Date** : 2026-11-05
- **Décideurs** : comité d'architecture
- **Dérives concernées** : D5 contrat · D1 revenu
- **Amende** : ADR-0118 §6
- **Précise** : ADR-0041 §5, ADR-0056, ADR-0074 §1 et §2, ADR-0117 §3

## Contexte

ADR-0118 §6 a décidé que la clé d'idempotence d'un message du Core est
`core-notice:{type d'événement}:{client}:{fenêtre}`, la fenêtre étant « la période qui
finit (abonnement), **la fenêtre de comptage** (usage) ou la facture (invoice) ».

Le code ne tenait pas la promesse. Pour un événement d'usage, il retenait `window_end` —
la fin de la **période** — et jamais le compteur ; pour un abonnement, la fin de période
seule, jamais l'abonnement.

Deux quotas d'un même plan peuvent être épuisés dans la même période. Leur fenêtre était
donc la même, leur clé aussi : le second message devenait un rejeu du premier, et le
client n'apprenait jamais que son second quota était épuisé. Or la fenêtre de comptage
(ADR-0074 §1) est **par compteur** — le compteur est indexé par
`(project_id, customer_id, meter, window_start)`. Deux compteurs sont deux fenêtres.

Le second cas est plus fin. Un pack acheté au milieu de la période relève la limite
(ADR-0117) : le client atteint 150 sur 150, et le message dit « (150/150) ». Ce n'est pas
le même message que « (100/100) ». Or la limite ne figurait pas dans la clé : le second
message était un rejeu du premier, et le client n'apprenait jamais que le quota qu'il
vient de payer est lui aussi épuisé.

## Décision

La fenêtre d'une notification **nomme la ressource** que l'événement concerne, et la
ressource est nommée avec ce qui la rend unique :

| Événement | Fenêtre |
|---|---|
| une facture | `invoice\|{invoice_id}` |
| un abonnement | `subscription\|{subscription_id}\|{fin de période}` |
| un usage | `meter\|{compteur}\|{début de fenêtre}\|{fin de fenêtre}\|{limite atteinte}` |

La clé garde sa forme — `core-notice:{type}:{client}:{fenêtre}` : c'est la fenêtre qui
devient plus précise, pas la clé.

Le rejeu d'un **même** événement (le relais republie en *au moins une fois*) retrouve la
même clé : la même charge utile porte le même compteur, la même fenêtre et la même
limite. Deux événements distincts ont deux clés distinctes, et deux messages.

## Alternatives écartées

- **Ajouter le compteur à la charge utile sans toucher à la fenêtre.** Écarté : la charge
  utile le porte déjà ; c'est la fenêtre — la notion qui manque — qu'il fallait préciser.
- **Ne pas se soucier du pack qui relève la limite.** Écarté : le client paie un pack et
  n'apprend pas qu'il l'a épuisé. Le message « (150/150) » n'est pas « (100/100) ».

## Conséquences

- La fenêtre d'usage porte désormais le compteur et la limite atteinte.
- Le catalogue (`app/Notices/CoreNoticeCatalogue.php`) lit la ressource dans la charge
  utile : il n'a pas de liste d'événements à maintenir.
- Un événement dont la charge utile ne porte aucune de ces ressources garde la fenêtre
  `none` : il n'y a qu'une notification de cet événement par client, ce qui vaut mieux
  qu'une rafale.

## Vérification

- Test unitaire (`tests/Unit/Notices/CoreNoticeDispatcherTest.php`) : le rejeu du même
  événement garde la même clé ; un autre compteur et une autre limite atteinte ont
  d'autres clés.
