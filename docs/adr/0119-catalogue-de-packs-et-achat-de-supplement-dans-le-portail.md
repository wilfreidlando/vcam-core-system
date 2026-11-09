# ADR-0119 — Catalogue de packs et achat de supplément dans le portail

- **Statut** : Proposé, amendé par ADR-0121 (§2 et §6)
- **Date** : 2026-11-02
- **Décideurs** : commanditaire (cadrage), comité d'architecture
- **Dérives concernées** : D1 périmètre · D5 contrat · D6 intégrité financière
- **Précise** : ADR-0117 §7 (le point de réévaluation), ADR-0117 §4, ADR-0075 §3, ADR-0074 §3, ADR-0107
- **Modifie le graphe** : non — l'arête `portal → billing` existe déjà (ADR-0075) ; `tests/Architecture/ModuleGraph.php` est inchangée

## Contexte

ADR-0117 a livré le **supplément de quota** : une quantité achetée qui s'ajoute à la
limite existante, sur le même compteur, sans renouveler l'abonnement. La décision est
adoptée, le code est là, la **limite effective** (limite du plan + Σ des suppléments
`active`) est calculée en un seul endroit et lue par la consommation comme par les droits
(ADR-0117 §3). Le portail affiche donc déjà la limite augmentée.

Mais ADR-0117 §7 a laissé trois choses **explicitement** hors de la décision :

> - **Pas de catalogue d'offres.** […] Un catalogue de packs par projet est le point de
>   réévaluation quand le **portail** achète pour le client final.
> - **Pas de console.** La console opérateur suivra, comme le reste.
> - **Pas de portail.** La route publique est un geste d'opérateur ; le parcours du client
>   final appartient au portail (ADR-0075).

Le catalogue est donc le point de réévaluation, et c'est ce point que cet ADR tranche.
Aujourd'hui, la seule surface d'achat est `POST /v1/subscriptions/{id}/supplements`
(ADR-0117 §4) : une **API opérateur**, portée `subscriptions:write`, avec un
`Idempotency-Key` obligatoire (G7). Un client final d'une auto-école ne connaît pas le
Core, ne possède pas de clé d'API et n'a aucune raison de savoir ce qu'est un compteur :
il ne peut pas acheter son quota.

Le besoin est simple à dire : « il doit pouvoir acheter un autre pack, pas renouveler
son abonnement ».

## Décision

### 1. Le catalogue des packs vit dans les réglages du projet

Un pack est **une déclaration du projet**, comme les `meter_mappings` qui associent une
capacité à un compteur (ADR-0074 §5). Il ne vit donc ni dans un plan (un plan porte des
droits, pas des ventes à l'unité), ni dans une table à part, ni dans le code du portail.

Il vit dans **`billing_settings`**, la ligne de réglages du projet (ADR-0071 §4), dans une
colonne `supplement_packs` (jsonb). La lecture et l'écriture passent par les use cases de
réglages **existants**, étendus :

- `SaveBillingSettingsCommand` porte les packs ;
- `BillingSettingsView` les rend ;
- `ManageBillingSettings` les lit et les écrit.

**Aucun use case nouveau** n'est créé pour le catalogue. La validation des packs est
appliquée par `ManageBillingSettingsUseCase`, comme les autres règles de la ligne de
réglages : le schéma ne porte aucune règle métier (ADR-0074 §1).

Comme `document_branding` et `retain_invoice_identity` — les deux réglages que la console
n'édite pas — les packs sont **reportés tels quels** quand la commande ne les porte pas :
un enregistrement de réglages depuis la console n'efface jamais le catalogue.

### 2. Ce qu'un pack contient

Un pack porte quatre choses, et rien d'autre :

| Champ | Sens |
| ----- | ---- |
| `meter` | le compteur du projet que le pack étend (`whatsapp_messages`) |
| `quantity` | le nombre d'unités ajoutées à la limite (≥ 1) |
| `unit_price_minor` | le prix d'une unité, en **unités mineures** (G5) |
| `label` | le libellé lu par le client (« Pack de 50 messages ») |

Un pack porte en outre un **`code`** — l'identifiant stable que le formulaire du portail
cite (« pack-50 »). Il n'est pas une valeur commerciale : c'est la clé sous laquelle le
projet range son offre, et c'est elle que le client final ne voit jamais.

La devise est celle du projet (`billing_settings.default_currency`), et le contrat doit
être dans cette devise : un pack déclaré en XAF sur un contrat en EUR est refusé à
l'achat, jamais converti au poids du compteur.

**Validité** — refusée par le code, jamais par le schéma :

- le `meter` est **connu du projet** : il est porté par au moins une version de plan
  publiée du projet, ou déclaré dans ses `meter_mappings` ;
- `quantity` ≥ 1 ;
- `unit_price_minor` ≥ 0 (G5) ;
- `label` non vide ;
- `code` unique dans le projet, au format d'un slug ;
- au plus 20 packs par projet.

### 3. Qui achète

**Le client final, dans le portail.** C'est la raison d'être de cet ADR : ADR-0117 §7
renvoie explicitement le parcours du client final au portail (ADR-0075), et ADR-0107
confirme que le Core porte la relation commerciale.

L'opérateur garde la route publique (`POST /v1/subscriptions/{id}/supplements`,
ADR-0117 §4) : elle achète une quantité et un prix **déclarés**, comme les lignes d'une
facture manuelle. Elle n'est pas retirée, et elle ne change pas.

Mais le portail ne passe **pas** par cette route : une route opérateur suppose une clé
d'API et une portée, ce qu'un client final n'a pas. Le portail passe par un **nouveau
port** de `billing`, `Contracts\PurchaseSubscriptionSupplementPack`, qui prend le **code
du pack** — jamais une quantité, jamais un prix. Le client choisit dans un catalogue que
le projet a déclaré ; il ne dicte pas le tarif. C'est ce qui distingue un achat du
portail d'une commande d'opérateur.

Le portail ne touche que `Contracts/` (G3c) : `Domain/` et `Application/` de `billing` lui
restent inaccessibles.

### 4. Ce qui déclenche l'achat

**L'offre ne dépend pas de l'usage.** Un pack est proposé dès que le projet en
propose un pour ce compteur : **pas de seuil**, pas de « quota épuisé », pas de « quota
presque atteint ».

La raison est celle du commanditaire : le client ne connaît pas le Core et ne doit pas
avoir à vérifier lui-même où il en est de son quota pour savoir qu'il peut en
acheter. Une offre qui n'apparaît qu'au moment précis où le compteur touche sa limite
est une offre que le client ne voit jamais — et le portail n'est pas une boutique
conditionnelle : c'est le **catalogue** du projet, dessiné en clair.

Deux conditions suffisent à retirer un pack de la page — et ce sont des conditions de
**droit**, pas d'usage :

- le contrat n'est **pas dans la devise du projet** : le Core refuserait l'achat
  (ADR-0117 §4), le portail n'offre donc jamais ce qu'il ne peut pas vendre ;
- un rechargement de ce compteur **attend déjà son paiement** : le client n'achète pas
  deux fois le même quota.

Un compteur **sans plafond** (`limit = null`) ne propose aucun pack : ADR-0117 §3 refuse
un supplément sur un compteur illimité, il n'y a rien à ajouter.

> Cette section **amende** la version initiale de la décision, qui proposait un seuil à
> 80 % de la limite. Le seuil est retiré : le code (`PortalWorkspace::usage()`) ne lit
> plus `used` pour décider d'afficher un pack.

### 5. Ce que le client voit

Le client final ne connaît pas le Core. Ni « meter », ni « entitlement », ni
« supplément » : ces mots n'apparaissent nulle part dans le portail.

Il voit :

- la jauge qu'il voit déjà (page « Utilisation »), dont la limite est la limite
  effective ;
- une phrase qui dit ce qui se passe : « Vous avez épuisé vos 500 messages. » ou « Il vous
  reste 20 messages sur 500. » ;
- la liste des packs du compteur concerné, avec le libellé du projet et le prix : « Pack de
  50 messages — 2 500 FCFA » ;
- un bouton « Recharger ».

Le nom du compteur vient de la **fonctionnalité du plan** (la clé que la jauge affiche
déjà), jamais du compteur lui-même.

**La facture produite par l'achat est affichée au client avec le chemin de paiement
existant** : le portail redirige vers la page de la facture, qui porte déjà le formulaire
de règlement (`POST /portal/invoices/{id}/pay`, ADR-0075 §5). Rien n'est réécrit. Le
client paie ; le supplément passe `active` dans la transaction qui règle la facture
(ADR-0117 §2) ; la limite effective monte ; les compteurs du portail le montrent.

**Un échec est une phrase, jamais une page technique.** Le portail translate chaque refus
en langage client :

| Cas | Message |
| --- | ------- |
| le pack a quitté le catalogue | « Ce pack n'est plus disponible. Choisissez-en un autre. » |
| l'abonnement ne porte plus le compteur | « Votre abonnement ne compte plus ce quota : contactez le support. » |
| le contrat a disparu | « Votre abonnement est introuvable. » |
| session en consultation seule | « Cette session est en consultation seule : le rechargement n'est pas possible. » |
| tout autre refus | « Le rechargement n'a pas pu être enregistré. Réessayez ou contactez le support. » |

Le motif technique est **journalisé**, jamais montré.

### 6. Le non-rejeu

Le geste d'achat du portail est une mutation d'argent : il émet une facture et inscrit
une créance au grand livre (ADR-0117 §4). Il est donc idempotent (G7).

Le portail n'a pas de client HTTP vers le Core — il appelle les contrats de `billing`
dans le processus. La clé d'idempotence est donc **dérivée côté serveur**, comme pour le
paiement d'une facture (ADR-0075 §5) :

```
portal-pack:<HMAC(session | code du pack | nonce du formulaire)>
```

Le `nonce` est celui du formulaire, tiré à chaque rendu de la page : un double clic rejoue
le **même** POST, donc la **même** clé, donc **un seul** achat. Une page rechargée donne
un nonce neuf — et une seconde commande, ce qui est un geste distinct et volontaire.

La clé est **durable** : le supplément porte la clé de sa commande, et la table porte un
index unique sur `(project_id, idempotency_key)`. Un rejeu retrouve le supplément
existant et **rend le même résultat** — le même supplément, la même facture — au lieu
d'acheter deux fois. C'est la primitive de concurrence de l'idempotence, pas une règle
métier (ADR-0098 §6).

La route publique de l'opérateur garde son `Idempotency-Key` HTTP (G7) : les deux
protections sont à des niveaux différents et ne se remplacent pas.

### 7. Ce qui reste hors périmètre

- **La console opérateur.** ADR-0117 §7 : « la console opérateur suivra ». Les packs
  s'écrivent aujourd'hui par `ManageBillingSettings` ; la zone de saisie du catalogue et
  le formulaire d'accord d'un pack **sont livrés** — la console opérateur n'est donc plus
  hors périmètre.
- **Une route publique d'achat de pack.** L'opérateur achète une quantité et un prix
  déclarés (ADR-0117 §4) ; le pack est un catalogue de portail, pas une API publique.
- **Le multi-devise.** Un pack est dans la devise du projet ; un contrat dans une autre
  devise n'achète pas de pack.
- **Un pack à durée de vie, un pack cumulatif, un pack à palier.** Le pack est une
  quantité, un prix, un libellé. Rien d'autre.
- **Un seuil d'affichage par projet.** Écarté : l'offre ne dépend pas de l'usage (voir
  §4). Le pack lui-même reste déclaré par le projet.
- **La commande d'un pack par l'API cliente.** L'application cliente ne fait pas de
  commerce (ADR-0107).

## Alternatives écartées

- **Laisser le client acheter par la route opérateur.** Rejeté : elle suppose une clé
  d'API et une portée `subscriptions:write` ; le client final n'a ni l'une ni l'autre, et
  ADR-0075/0107 disent que le commerce du client final appartient au portail.
- **Laisser le client déclarer la quantité et le prix (comme la route opérateur).**
  Rejeté : le client fixerait son propre tarif. Un catalogue déclaré par le projet est
  la seule version qui tient la promesse commerciale.
- **Un plan « plus » qui porte plus de quota.** Rejeté : ADR-0117 le refuse — cela
  changerait de contrat, donc de période et de facture. Le client veut une quantité, pas
  un autre abonnement.
- **Une deuxième subscription.** Rejeté : ADR-0117 — le compteur est indexé par
  `(projet, client, compteur, fenêtre)` ; une seconde subscription n'y ajoute rien.
- **Une table `supplement_packs` à part.** Rejeté : le catalogue est un réglage du
  projet, au même titre que `meter_mappings` (ADR-0074 §5) ; une table de plus pour une
  déclaration de configuration n'apporterait rien.
- **Un packs dans le plan.** Rejeté : un plan est figé à la publication (ADR-0071 §2) et
  porte des droits, pas des ventes à l'unité ; un pack se déclare et se retire sans
  toucher aux contrats signés.
- **Proposer les packs en permanence.** Retenu, contre l'opinion initiale de ce
  document : c'est la décision du commanditaire (§4). Le portail n'est pas une boutique
  *poussée* — le catalogue est dessiné en clair, sans message d'insistance, et le client
  choisit.
- **Recharger le quota par un paiement direct** (un montant libre). Rejeté : le prix
  deviendrait un choix du client ; le pack est une offre, pas un Crowdfunding.

## Conséquences

- `billing` gains une colonne (`billing_settings.supplement_packs`), un modèle de domaine
  (`SupplementPack`), deux contrats étendus (`SaveBillingSettingsCommand`,
  `BillingSettingsView`), **un** contrat nouveau (`PurchaseSubscriptionSupplementPack`),
  un use case (`PurchaseSubscriptionSupplementPackUseCase`) et une colonne d'idempotence
  sur `subscription_supplements`.
- `portal` gagne une section sur la page « Utilisation », une route `POST /portal/packs`
  et un parcours de rechargement.
- Le graphe de modules **ne change pas** : `portal → billing` existait déjà (ADR-0075) ;
  `tests/Architecture/ModuleGraph.php` est inchangée.
- ADR-0117 §4 est **précisé** : la route opérateur reste telle quelle ; le catalogue
  s'y ajoute par un port distinct.
- La limite effective reste calculée en **un seul endroit** (ADR-0117 §3) : le portail
  n'en calcule aucune — il lit les droits.

## Vérification

- Test d'intégration (`billing`) : un catalogue invalide est refusé (compteur inconnu,
  quantité nulle, prix négatif, libellé vide, code en double) ; un pack d'un autre
  projet n'appartient jamais au catalogue de ce projet ; l'achat d'un pack crée le
  supplément et sa facture, et **rien** avant le règlement.
- Test d'intégration (`portal`) : l'achat depuis le portail produit une facture, le
  règlement l'active, la limite effective augmente et les compteurs du portail le
  montrent ; le **rejeu** de la même clé ne produit qu'un supplément et qu'une facture ;
  un pack d'un autre projet n'est **jamais proposé** ; la page ne dit ni « meter », ni
  « entitlement », ni « supplément ».
- Non-régression : la suite `billing` et la suite `portal` restent vertes ; le garde-fou
  G7 (routes d'argent) reste vert — la route du portail est un plan navigateur, comme
  les autres gestes du portail, et sa clé est dérivée côté serveur.
