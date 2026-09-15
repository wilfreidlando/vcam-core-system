# ADR-0038 — Conception Lot 3 Payment : channel canonique, routage vers le provider, état du paiement, notifications

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Dérives concernées** : D1, D2, D4, D6

## Contexte

Le Lot 3 a aujourd'hui un premier adaptateur concret (MyCoolPay, agrégateur —
`eaf4709`) et un registre de providers typé et sûr (`PaymentGatewayRegistry`,
détection des doublons de code — voir le futur ADR qui documentera ce
registre). Ce qui reste ouvert, posé explicitement par le demandeur avant que
le code ne s'y engage : le jour où un deuxième provider arrive — pas un autre
agrégateur, mais un adaptateur **direct** vers un opérateur (MTN, Orange),
avec ses propres endpoints cash-in/cash-out/marchand — comment la surface
publique de `Payment` absorbe ça sans jamais exposer « MyCoolPay » ou
« MTN » comme des concepts que l'application appelante doit connaître ?
Trois questions concrètes en découlent, posées dans la conversation :

1. Un utilisateur final choisit explicitement « payer avec MTN » dans une
   app — comment ce choix arrive-t-il au bon adaptateur sans que l'app
   appelante sache laquelle des classes PHP le traite ?
2. Le statut d'un paiement et les webhooks entrants doivent « bien chercher
   en fonction du provider » — comment, sans recherche à l'aveugle sur tous
   les adaptateurs enregistrés ?
3. « Le paiement doit prendre en compte la callback de l'app qui l'a
   initié » — quel mécanisme, et est-ce le même que celui déjà prévu par
   [ADR-0009](0009-webhooks-sortants-signes.md) ?

Le fil conducteur : construire ça comme un système qui **absorbe l'ajout
d'un provider par une modification additive et interne** (une classe, une
ligne de config), jamais par un changement de contrat public ou une
multiplication d'endpoints.

## Décisions

### 1. `channel` (public, canonique) est distinct de `provider` (interne, jamais exposé)

Un utilisateur qui choisit « payer avec MTN » choisit un **moyen de
paiement**, pas une société qui traite l'appel API. Ce sont deux concepts
différents que le système ne doit jamais confondre :

- **`channel`** — vocabulaire canonique public (`mtn_momo`, `orange_money`,
  `card`…), porté par la requête d'initiation
  (`InitiatePaymentCommand->channel`, champ à ajouter — pas encore
  présent). C'est légitime à exposer : une app a besoin de proposer ce
  choix à son utilisateur, et ce choix ne change pas selon qui traite
  l'appel derrière.
- **`provider`** (`ProviderCode`, déjà existant) — quel adaptateur
  (`MyCoolPayAdapter`, demain `MtnDirectAdapter`) traite réellement l'appel
  pour ce `channel`, pour ce Project. Ne sort jamais du module `Payment` ;
  n'apparaît dans aucune réponse HTTP, aucun tag de documentation.

`channel` est orthogonal à `PaymentFlow` (`Payin`/`Paylink`, déjà livré) :
`flow` décrit *comment* l'interaction utilisateur se déroule (app-driven vs
redirection), `channel` décrit *quel rail* déplace l'argent. Un paiement
`channel: mtn_momo, flow: payin` et un paiement `channel: orange_money,
flow: paylink` sont deux combinaisons valides indépendantes.

### 2. Résolution du provider : une règle de routage `(channel, Project) → provider`, pas un paramètre choisi par l'appelant

L'appelant ne choisit jamais l'adaptateur — il choisit le `channel`, et le
Core résout en interne quel provider le sert, **pour ce Project**. C'est ce
qui permet à deux Projects différents d'utiliser deux providers différents
pour le même `channel` sans que leurs intégrations respectives changent
d'une ligne : aujourd'hui `channel: mtn_momo` peut résoudre vers
`MyCoolPayAdapter` pour le Project A (qui n'a que l'agrégateur configuré) et
vers un futur `MtnDirectAdapter` pour le Project B — même requête, même
réponse, routage invisible.

**Décision** : une table/config explicite de routage
`(channel, project_id) → provider_code`, avec un `provider_code` par défaut
au niveau plateforme quand le Project n'a pas de préférence propre. Reste
volontairement simple : pas de routage par coût, disponibilité ou
géographie tant qu'il n'existe pas un deuxième provider réel et un besoin
mesuré de les arbitrer (porte n°3 de la gouvernance — gain > coût). Le jour
où ce besoin existe, cette table est l'endroit où il s'implémente ; elle
n'a pas besoin d'exister par anticipation.

### 3. `Payment` possède son propre état — l'agrégat retient son provider

Aucune table d'état pour `Payment` n'existe encore. Elle est nécessaire
pour que les décisions 4 et 5 ci-dessous soient possibles, et retient au
minimum, par paiement : le `channel` demandé, le `provider_code` qui l'a
réellement traité (résultat de la décision 2, figé à l'initiation — un
paiement ne change jamais de provider en cours de route), la
`transactionRef` côté provider, le statut canonique courant, et le Project
initiateur.

### 4. Un statut se vérifie contre l'adaptateur enregistré pour ce paiement, jamais contre tous les providers

`GET /v1/payments/{id}` lit d'abord l'état interne (rapide, pas d'appel
sortant). Quand une réconciliation live est nécessaire (statut périmé,
webhook manqué), le Core appelle `checkStatus()` sur **le provider retenu à
l'initiation** (décision 3) via `PaymentGatewayRegistry::gatewayFor()`
— jamais une recherche par essai-erreur sur les providers disponibles. Le
choix de provider étant figé par paiement, cette résolution est directe et
déterministe.

### 5. Les webhooks entrants restent par provider — ce n'est pas une fuite

`POST /webhooks/{provider}` (déjà le design existant : `/webhooks/my-coolpay`,
demain `/webhooks/mtn-momo`) reste nécessairement spécifique à chaque
provider — schémas de signature et de payload différents, inévitable. Ce
n'est **pas** une fuite d'abstraction au sens de G6/D4 : ce n'est pas une
route que l'application consommatrice appelle, c'est le canal entrant
*depuis* le provider, jamais documenté dans la surface API publique
(`config/scramble.php` l'exclut déjà — `api_path => 'v1'`, les webhooks
vivent sous `/webhooks`). Chaque contrôleur webhook traduit le payload brut
en `PaymentStatusChanged` canonique via son `StatusMapper` (déjà le
pattern MyCoolPay), retrouve le paiement par `transactionRef`, et met à
jour l'état retenu en décision 3.

### 6. Deux notions de « callback » à ne pas fusionner

La demande « le paiement doit prendre en compte la callback de l'app qui
l'a initié » recouvre en réalité deux préoccupations aux garanties très
différentes — les confondre dans un seul mécanisme serait une erreur :

- **Notification serveur-à-serveur** (« préviens mon backend que le
  paiement a réussi ») — **ne réinvente rien** : passe par le mécanisme déjà
  accepté par [ADR-0009](0009-webhooks-sortants-signes.md) (module
  `Webhook`, Lot 4) — le Project s'abonne une fois pour toutes
  (`webhook_endpoints.events`) à des types d'événements incluant
  `payment.completed`/`payment.failed`, livrés avec signature HMAC, retry,
  DLQ, replay. `PaymentStatusChanged` (déjà publié dans l'outbox depuis le
  premier adaptateur) est exactement l'événement que ce relais consommera
  une fois construit. Pas de `callback_url` ad hoc par requête pour ce
  besoin — un mécanisme fiable avec garanties de livraison existe déjà sur
  le papier, le dupliquer serait la dérive D2 (couplage) par un autre
  chemin.
- **Redirection navigateur** (flow `Paylink` uniquement — « renvoie
  l'utilisateur ici une fois qu'il a payé sur la page hébergée ») — un
  besoin réellement différent : pas de garantie de livraison à offrir (le
  navigateur peut fermer l'onglet), pas un événement métier, juste un
  aiguillage UX à usage unique propre à cette requête précise. **Décision** :
  un champ `returnUrl` optionnel sur `InitiatePaymentCommand`, transmis tel
  quel au provider par l'adaptateur quand le `flow` est `Paylink` (MyCoolPay
  et la plupart des agrégateurs à page hébergée acceptent déjà ce
  paramètre nativement). Ignoré si `flow` est `Payin`.

### 7. La documentation publique (Swagger) garde un tag par module, jamais par provider

Confirme et fige ce qui a été discuté : `Payment` documente
`POST /v1/payments`, `GET /v1/payments/{id}`, `POST /v1/payments/{id}/actions`
sous un unique tag « Payment » dans `config/scramble.php`. Aucun endpoint,
aucun tag, aucun schéma nommé d'après un provider n'apparaît dans
`openapi/v1.json` — un adaptateur qui fuiterait dans la doc publique serait
un signal que l'anti-corruption layer (G6) a une fissure quelque part.

## Alternatives écartées

- **Un endpoint par provider** (`POST /v1/payments/mycoolpay/...`,
  `POST /v1/payments/mtn/...`) : rejeté — fuite directe de l'abstraction,
  couple chaque app appelante à un provider spécifique au lieu du contrat
  canonique, multiplie la surface publique à chaque nouveau provider.
- **Laisser l'appelant choisir le provider explicitement** (`provider:
  "my-coolpay"` dans la requête) : rejeté — revient à exposer les
  providers avec une étape de plus ; empêche de changer de provider pour un
  channel donné sans casser les intégrations existantes.
- **Un seul mécanisme de callback** fusionnant notification serveur et
  redirection navigateur : rejeté — garanties de livraison incompatibles
  (fiable avec retry/DLQ vs aiguillage best-effort), déjà deux ADR séparées
  (0009 vs celle-ci) avec des propriétaires de module différents
  (`Webhook` vs `Payment`).
- **Routage provider par coût/pays/failover dès maintenant** : rejeté —
  prématuré tant qu'un seul provider réel existe ; la table de routage
  (décision 2) est l'endroit prévu pour ça le jour où c'est justifié.

## Conséquences

- `InitiatePaymentCommand` (Contracts) gagne `channel` et `returnUrl?` —
  non implémenté par cet ADR (le module est en cours de correction active
  par ailleurs) ; sert de référence pour qui l'implémente.
- `Payment` a besoin d'une vraie table d'état (migration + Domain model),
  non encore construite — condition préalable aux décisions 3 et 4.
- Une table/config de routage `(channel, project_id) → provider_code` est à
  concevoir avant que `POST /v1/payments` existe réellement.
- G7 (`Idempotency-Key` obligatoire) s'active avec `POST /v1/payments` —
  première route de mutation monétaire publique du Core (ADR-0033 §2).
- Le catalogue d'événements du futur module `Webhook` (Lot 4) doit inclure
  `payment.completed`/`payment.failed`/`payment.canceled` parmi les types
  auxquels un Project peut s'abonner.
- Aucun fichier de `packages/payment` n'est modifié par cet ADR — un autre
  agent y travaille activement ; ce document sert de référence de
  conception pour la suite de son implémentation.
