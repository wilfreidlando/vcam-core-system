# ADR-0091 — Décisions implicites du code livré, formalisées

- **Statut** : Accepté
- **Date** : 2026-10-27
- **Décideurs** : comité d'architecture (ratification du 2026-10-27), sur
  proposition de l'implémentation (lots 4 à 8)
- **Amende** : ADR-0040 (webhooks sortants), ADR-0052 (console admin),
  ADR-0065 (opérations de wallet)
- **Dérives concernées** : D2 couplage · D5 contrat · D6 intégrité financière

## Contexte

Sept contrats publics ont été introduits par le code **sans qu'un ADR ne les
décrive**. Le code a livré la décision avant que la décision ne soit écrite :
c'est un défaut de gouvernance, pas un défaut de code. Un contrat non écrit
finit par être lu différemment par celui qui l'a écrit et par celui qui le
consomme, et « c'est comme ça dans le code » n'est pas une spécification.

Le comité d'architecture a ratifié la série 0068→0090 le 2026-10-27. Sept de
ces ADR avaient déjà leur code livré et conforme au moment de la décision. Il
restait ces décisions-ci, jamais écrites. Le présent ADR ne crée rien : il
**enregistre** ce que le code fait déjà, avec les garanties que le code applique
déjà. C'est un ADR « ratifié par le code antérieur » — la trace inverse de
l'ADR-0091 → code.

## Décision

### 1. Un compte se résout ou se crée — il ne se forge jamais (ADR-0065)

`Core\Ledger\Contracts\AccountResolver` —
`resolveOrCreate(ResolveOrCreateAccountCommand): AccountRef`.

Un compte du grand livre est identifié par le quadruplet
`(project_id, kind, reference, currency)` et par rien d'autre. L'appelant ne
fabrique **jamais** un identifiant de compte : il décrit le compte voulu
(nature, référence, devise) et le port le résout — ou le crée si besoin. La
création est sûre face à la concurrence : la contrainte d'unicité sur le
quadruplet fait qu'une résolution concurrente du même compte rend le même
compte, pas deux (le perdant relit la ligne gagnante). Un `kind` inconnu est
refusé, pas tronqué.

Le ledger est le seul juge de l'existence d'un compte : un module appelant
`AccountResolver` n'écrit jamais dans `ledger_accounts` et ne décide jamais de
la vie d'un compte. `kind` distingue aujourd'hui `wallet` et `clearing` ; le
plan de comptes complet d'ADR-0076 (lot 9) étendra cet ensemble.

### 2. La devise supportée est une question du ledger, pas de l'appelant (ADR-0065)

`Core\Ledger\Contracts\IsSupportedCurrency` — `isSupported(string $currencyCode): bool`.

Un prédicat scalaire, pas un port « Command » : même forme que
`OperatorContext::hasPermission()` ou `ProjectContext::hasScope()`, le motif
établi dans ce dépôt pour une vérification à valeur unique.

Le transfert entre wallets est **mono-devise** : toutes ses jambes portent la
même devise, et cette devise doit être supportée par la plateforme, sinon
l'opération est refusée avant toute écriture (`InvalidWalletOperation`). Le
contrat refuse donc une conversion implicite — la conversion n'est pas une
fonction du ledger actuel, et le laisser supposer serait un mensonge du
contrat. Une opération de change devra être un cas d'usage explicite.

### 3. Une organisation et un projet se modifient par des ports dédiés (ADR-0052)

`Core\Identity\Contracts\UpdateProject`, `Core\Identity\Contracts\UpdateOrganization`,
`Core\Identity\Contracts\GetOrganization`.

Modifier une organisation ou un projet passe par un port d'écriture qui porte
l'acteur et l'identifiant de corrélation — la modification est donc auditée
avec la même discipline que le reste de la console, et non par une écriture
directe depuis un contrôleur. Un identifiant qui ne résout pas est un refus
(`RuntimeException`), jamais une création implicite.

`GetOrganization` est le seul des trois qui soit une lecture : il reçoit
l'`AccessScope` en **paramètre requis** et lève la même exception si
l'organisation n'existe pas **ou** si elle est hors de la portée de l'appelant.
Il ne distingue pas les deux cas — c'est ADR-0069 S-04 : une ressource
étrangère ne se confirme pas.

### 4. Un endpoint de webhook a trois opérations distinctes, jamais une (ADR-0040)

`Core\Webhook\Contracts\ActivateWebhookEndpoint`,
`Core\Webhook\Contracts\DeactivateWebhookEndpoint`,
`Core\Webhook\Contracts\UpdateWebhookEndpoint`.

L'activation et la désactivation sont des opérations **distinctes**, chacune
dans son port : un flag d'activité n'est pas un champ modifiable au milieu
d'autres. `UpdateWebhookEndpoint` ne touche qu'à l'URL et aux événements
souscrits ; **jamais** au secret de signature (sa rotation est une opération
plus sensible, avec sa propre preuve — ADR-0082 §5) et **jamais** au flag
d'activité. Un endpoint qui n'existe pas, ou qui appartient à un autre projet,
lève `RuntimeException` — l'appartenance au projet est vérifiée dans le port,
jamais laissée à la confiance du appelant.

## Alternatives écartées

- **Un seul port `UpdateWebhookEndpoint` avec un champ `active`.** Rejeté :
  un port qui change l'activité d'un endpoint et son URL dans le même appel
  n'a plus de garantie que la rotation du secret reste isolée ; la séparation
  rend la règle visible dans la signature du contrat.
- **Un port `CreateAccount` distinct de `AccountResolver`.** Rejeté : le cas
  « existe déjà » est le cas normal d'un transfert réémis, pas une erreur à
  traiter. Un seul point d'entrée, résout ou crée, évite la course entre les
  deux.
- **Laisser ces contrats sans ADR** (« le code est la spécification »).
  Rejeté : c'est précisément ce qui a produit cet ADR de rattrapage. Un
  contrat non écrit se lit de deux façons au premier changement d'auteur.
- **Réécrire les ADR-0040/0052/0065 pour y intégrer ces décisions.** Rejeté :
  un ADR accepté ne se réécrit pas — on le **remplace** par un nouvel ADR et on
  met à jour le statut de l'ancien. Le présent ADR est l'amendement.

## Conséquences

- Trois ADR portent désormais « amendé par ADR-0091 » : ADR-0040, ADR-0052 et
  ADR-0065. Leur texte n'est pas modifié ; ils pointent vers ici.
- Aucun code n'est modifié par le présent ADR : les huit contrats décrits
  existent, sont implémentés, testés et respectent déjà ce qui est écrit.
- La règle de gouvernance qui manquait est écrite : **un contrat public ajouté
  au `Contracts/` d'un module sans ADR est un défaut**, pas une simplification
  anodine. Elle s'applique à partir de ce lot.
- Le plan de comptes complet (ADR-0076, lot 9) étend `AccountKind` ; la
  résolution de compte reste le seul point d'entrée.

## Vérification

- `AccountResolver` : deux résolutions concurrentes du même quadruplet rendent
  le même compte (test d'intégration du ledger).
- `IsSupportedCurrency` : un transfert dans une devise non supportée est
  refusé avant toute écriture.
- `UpdateProject` / `UpdateOrganization` : un identifiant inconnu est refusé ;
  la modification est auditée.
- `GetOrganization` : une organisation hors de la portée lève la même
  exception qu'une organisation inexistante (ADR-0069 S-04).
- `ActivateWebhookEndpoint` / `DeactivateWebhookEndpoint` /
  `UpdateWebhookEndpoint` : un endpoint d'un autre projet est refusé par le
  port.
