# 11 — Les suppléments de quota

*Ce chapitre explique une quantité **achetée en plus** d'un quota, sur le même compteur,
sans renouveler l'abonnement. La décision est dans
[ADR-0117](../../adr/0117-supplements-de-quota-et-achat-additionnel.md) ; le chapitre
[04 — Commerce](04-commerce.md) donne le commerce lui-même.*

---

## 1. Le problème, en une phrase

Un client a un abonnement « pro » qui donne **500 messages WhatsApp par mois**. Au milieu
du mois, le quota est épuisé. Sans supplément, il a deux choix : attendre le
renouvellement, ou **renouveler** — mais renouveler ne lui rend pas plus de messages
jusqu'au mois suivant, et cela coûte le prix du plan.

Un **supplément** résout cela : le client achète **50 messages de plus**, qui s'ajoutent
aux 500, sur le même compteur, et son abonnement n'est ni renouvelé, ni modifié.

> Un supplément n'est **pas** un abonnement, et **pas** un renouvellement. C'est un achat
> ponctuel attaché à un abonnement existant.

---

## 2. Où c'est géré aujourd'hui

Le Core sait **faire** l'opération, la **console opérateur** la déclare et l'accorde, et le
**portail client** la propose au client. Voici l'état exact, sans arrondi :

| Surface | Existe ? | Où |
|---|---|---|
| **Catalogue de packs du projet** | ✅ oui | console → *Réglages du commerce* → « Packs de quota » |
| **Achat par le client dans le portail** | ✅ oui | page *Utilisation* du portail → « Recharger mon quota » |
| **Achat par l'opérateur (console)** | ✅ oui | page *Abonnement* → « Suppléments de quota » |
| **Achat par l'API opérateur** | ✅ oui | `POST /v1/subscriptions/{id}/supplements` (`packages/billing/routes/api.php`) |
| **Lecture de l'historique** | ✅ oui | `GET /v1/subscriptions/{id}/supplements` |
| **Effet sur le quota** | ✅ oui | `EffectiveLimitResolver` — la limite effective |
| **Prise en compte par le portail** | ✅ oui | le portail affiche la limite effective |

### Le catalogue de packs

Un pack est une **déclaration du projet** : le compteur étendu, la quantité, le prix unitaire
et le libellé que le client lit. Il vit dans les réglages du commerce, avec les
correspondances capacité → compteur.

La console l'écrit en une ligne par pack :

```
code | compteur | quantité | prix unitaire | libellé
```

```
pack50 | whatsapp_messages | 50 | 5000 | Pack de 50 messages WhatsApp
```

Le **prix** est en unités mineures de la devise du projet (pour XAF, des francs : 5 000 = 5 000
FCFA). Le **code** est en minuscules ; c'est lui que le client cite dans le portail, jamais un
prix ni une quantité.

### Les règles que le Core applique

- Un pack vise un compteur que le projet a **déclaré** et que l'offre du contrat porte ;
  sinon il n'aurait jamais d'effet.
- Les packs sont tarifés dans la **devise du projet** : un contrat dans une autre devise ne
  peut pas les acheter. Changer la devise du projet alors qu'il propose des packs est refusé —
  il faut les retirer d'abord.
- Un quota déjà rechargé et **en attente de paiement** n'est plus proposé : on ne vend pas
  deux fois le même quota à la même personne.
- Un pack gratuit n'existe pas : une facture de zéro n'existe pas.

---

## 3. La vie d'un supplément

Un supplément est **acheté avant de compter**. Il ne donne rien tant que l'argent n'est pas
arrivé.

```
   achat                facture réglée          facture annulée ou irrécouvrable
 ─────────►  pending  ────────────────►  active
   (écrit)              (l'allocation
                         est accordée)
              │
              └───────────────────►  canceled
                                    (rien n'a jamais été accordé)
```

| Statut | Ce qu'il veut dire | Effet sur la limite |
|---|---|---|
| `pending` | acheté, facture émise, pas encore payée | **rien** |
| `active` | la facture est réglée : l'allocation est accordée | **ajoute** la quantité |
| `canceled` | la facture a été annulée ou passée en irrécouvrable **avant** tout encaissement | **rien** |

> Un supplément `active` ne revient **jamais** à `canceled` : l'allocation est
> acquise, elle ne se reprend pas. Seule une facture qui n'a jamais été encaissée
> annule le supplément qu'elle porte.

> **Seul `active` ajoute au quota.** Une commande non payée n'est pas un droit — c'est
> pour cela que l'activation se fait dans la transaction qui règle la facture, jamais au
> moment de la commande.

Les trois états sont visibles par l'API de lecture et par les événements.

---

## 4. La limite effective — le point clé

La quantité que le client peut réellement consommer est :

```
limite effective  =  limite du plan  +  Σ (quantités des suppléments de la période)
```

> **Un pack couvre la période payée**
> ([ADR-0121](../../adr/0121-les-corrections-que-la-relecture-du-lot-des-quotas-a-apportees.md)).
> Un pack de 50 messages acheté le 15 novembre est **50 messages de plus en novembre** ;
> en décembre, le quota repart de celui du plan. Un pack est un achat ponctuel, pas une
> hausse de quota à vie.

Cette somme est calculée **en un seul endroit** :
`Core\Billing\Application\Service\EffectiveLimitResolver`. C'est la **source unique de
vérité**, lue par les deux endroits qui doivent dire la même chose :

| Lecteur | Ce qu'il en fait |
|---|---|
| `ConsumeUsageUseCase` | **applique** la limite : le compteur refuse de dépasser |
| `GetEntitlementsUseCase` | **annonce** la limite : les droits affichés au portail |

Conséquence : le nombre affiché au client et le nombre réellement contrôlé **ne peuvent
pas diverger**. Une limite qui augmente ne peut l'être que par un supplément `active` ;
un compteur qui a déjà consommé 480 messages sur une limite de 500 continue de
fonctionner quand la limite passe à 550.

> Le compteur n'est **pas** remis à zéro par un supplément : c'est le même compteur, avec
> une limite plus haute. C'est toute la différence avec un renouvellement.

### Exemple

| Moment | Limite du plan | Suppléments actifs | Limite effective | Reste |
|---|---|---|---|---|
| Départ | 500 | — | 500 | 500 |
| Après 480 messages | 500 | — | 500 | 20 |
| Pack de 50 acheté, **facture payée** | 500 | 50 | **550** | 70 |
| Pack acheté, **facture pas encore payée** | 500 | — | 500 | 20 |

---

## 5. Comment le portail en tient compte

Le portail ne lit pas les suppléments lui-même. Il lit les **droits** de l'abonné, et les
droits sont calculés à partir de la limite effective :

```
portail → droits du client → GetEntitlementsUseCase → EffectiveLimitResolver
                                                              ↑
                                      (plan + suppléments de la période)
```

Concrètement, sur la page d'**usage** du portail, le compteur affiché (limite, utilisé,
reste) porte **déjà** la limite augmentée. Un client qui a acheté un pack voit « 550
messages, 480 utilisés, 70 restants » — sans que le portail sache quoi que ce soit des
suppléments.

> Un détail d'affichage : la ligne du quota apparaît **une seule fois** dans l'espace
> client, même quand la capacité est déclarée par deux noms (par exemple
> `notification.whatsapp` et le meter `whatsapp_messages`). Les deux portent les mêmes
> chiffres ; le portail n'en montre qu'une.

### L'offre dans le portail

La page *Utilisation* propose les packs du catalogue qui visent ce compteur — **quand le
quota est épuisé comme quand il ne l'est pas**. L'offre ne dépend pas de l'usage : le
client ne connaît pas le Core, il n'a pas à vérifier lui-même où il en est de son quota
pour savoir qu'il peut acheter une quantité de messages en plus. Le catalogue est dessiné
en clair, sans message d'insistance.

Deux situations retirent une offre de la page — ce sont des questions de **droit**, pas
d'usage :

- le contrat du client n'est **pas dans la devise du projet** : le Core refuserait
  l'achat, le portail n'offre donc jamais ce qu'il ne peut pas vendre ;
- un rechargement de ce compteur **attend déjà son paiement** : le client n'achète pas
  deux fois le même quota.

Là où le client clique, le Core :

1. vérifie que le pack est encore au catalogue (il a pu être retiré entre-temps) ;
2. vérifie que l'abonnement porte toujours ce compteur ;
3. vérifie qu'aucun rechargement de ce compteur n'attend déjà son paiement ;
4. crée le supplément **et sa facture** — une facture comme les autres, payable par le chemin
   de paiement habituel.

Le client ne cite jamais un prix : il cite le **code** du pack. Ce qui a été payé est écrit
dans la facture, avec le libellé du pack.

---

## 6. L'API opérateur

### Acheter un supplément

```
POST /v1/subscriptions/{subscriptionId}/supplements
Portée : subscriptions:write
En-tête : Idempotency-Key: <uuid>        (obligatoire — G7)
```

```json
{
  "meter": "whatsapp_messages",
  "quantity": 50,
  "unit_price_minor": 5000,
  "description": "Pack de 50 messages WhatsApp"
}
```

| Champ | Rôle | Contrainte |
|---|---|---|
| `meter` | le compteur concerné | le nom du meter **du projet**, `[a-z0-9_.]{1,64}` |
| `quantity` | combien d'unités sont ajoutées | entier ≥ 1 |
| `unit_price_minor` | le prix d'une unité, en **centimes** de la devise du contrat | entier ≥ 1 |
| `description` | le libellé de la ligne de facture | facultatif, ≤ 500 caractères |

La réponse porte **deux** objets : le supplément (`pending`) et la **facture** émise
(numéro attribué, créance inscrite au grand livre). Le client paie cette facture par le
chemin habituel ; au règlement, le supplément devient `active`.

### Lire l'historique

```
GET /v1/subscriptions/{subscriptionId}/supplements
Portée : subscriptions:read
```

Chaque ligne : `id`, `meter`, `feature_key`, `quantity`, `unit_price_minor`,
`total_minor`, `currency`, `status`, `invoice_id`, `invoice_number`, `activated_at`,
`canceled_at`.

### Ce que l'API refuse

| Cas | Réponse |
|---|---|
| Un abonnement d'un autre projet | `404` — jamais « il existe mais pas pour toi » |
| Un abonnement qui ne donne aucun droit | `422` |
| Un meter que le plan ne porte pas | `422` |
| Un meter du plan qui n'a pas de limite | `422` |
| La même clé d'idempotence rejouée | la **première** réponse, sans rien réécrire |

---

## 7. Les événements

Trois événements, publiés par l'outbox transactionnel (G8), donc jamais perdus et
jamais doublés :

| Événement | Quand | Ce qu'il porte |
|---|---|---|
| `subscription.supplement_purchased` | la commande est passée | `supplement_id`, `subscription_id`, `customer_ref`, `meter`, `quantity`, `total_minor`, `currency`, `invoice_id`, `invoice_number`, `status` |
| `subscription.supplement_activated` | la facture est réglée | `supplement_id`, `subscription_id`, `meter`, `quantity`, `status`, `invoice_id` |
| `subscription.supplement_canceled` | la facture est annulée ou irrécouvrable | `supplement_id`, `subscription_id`, `meter`, `quantity`, `status`, `invoice_id` |

Un produit qui veut savoir « ce client a-t-il rechargé ? » s'abonne à
`subscription.supplement_activated` — c'est l'événement qui dit que le quota a vraiment
augmenté.

---

## 8. Ce que le Core écrit en base

La table `subscription_supplements` (migration
`2026_11_01_120000_create_subscription_supplements_table.php`) porte, par supplément :
l'identifiant, le projet, l'abonnement, le client, le meter, la clé de fonctionnalité,
la quantité, le prix unitaire, la devise, le statut, la facture, la période, et les
horodatages d'activation et d'annulation.

**Aucune contrainte en base** n'y porte les règles : les règles vivent dans le code
(« pas de SQL brut, pas de contraintes applicatives dans les migrations »). Une seule
exception, la clé unique de l'**idempotence** — qui est une primitive, pas une règle
métier.

---

## 9. Ce que le Core ne fait pas encore

Deux choses sont **explicitement** laissées de côté, et le disent :

1. **Pas de packs multi-compteurs.** Un pack vise **un** compteur. Un pack « 50 messages +
   10 SMS » n'existe pas.
2. **Pas de rachat.** Un supplément s'ajoute au quota ; il ne le remplace pas et ne
   prolonge pas la période. Pour un nouveau prix ou une nouvelle périodicité, c'est un
   changement d'offre (le portail le propose déjà).

---

## 10. Comment vérifier en deux minutes

```bash
# 1. Lire les droits du client — la limite effective y est.
curl -s -H "Authorization: Bearer $CLE" \
  "$CORE/v1/customers/AE0001/entitlements" | jq '.data.entitlements'

# 2. Lire les suppléments de l'abonnement.
curl -s -H "Authorization: Bearer $CLE" \
  "$CORE/v1/subscriptions/$ABONNEMENT/supplements" | jq '.data'
```

Sur la ligne de quota, la `limit` affichée est la **limite effective** : si elle est
supérieure à la limite du plan, c'est qu'un supplément est `active`.

---

## Repères techniques

| Élément | Emplacement |
|---|---|
| Décision | [ADR-0117](../../adr/0117-supplements-de-quota-et-achat-additionnel.md) et [ADR-0119](../../adr/0119-catalogue-de-packs-et-achat-de-supplement-dans-le-portail.md) |
| Limite effective | `packages/billing/src/Application/Service/EffectiveLimitResolver.php` |
| Activation au règlement | `packages/billing/src/Application/Service/SubscriptionSupplementActivator.php` |
| Achat | `packages/billing/src/Application/UseCase/PurchaseSubscriptionSupplementUseCase.php` |
| Achat d'un pack | `packages/billing/src/Application/UseCase/PurchaseSubscriptionSupplementPackUseCase.php` |
| Catalogue | `packages/billing/src/Domain/Model/SupplementPack.php` (les règles) et `billing_settings.supplement_packs` (la déclaration) |
| Lecture | `packages/billing/src/Application/UseCase/ListSubscriptionSupplementsUseCase.php` |
| Routes | `packages/billing/routes/api.php` |
| Console | `app/Http/Controllers/Admin/ProjectCommerceController.php` (catalogue) et `BillingSubscriptionsController.php` (accorder) |
| Table | `subscription_supplements` |
| Portail | `packages/portal/` (page d'usage) |
