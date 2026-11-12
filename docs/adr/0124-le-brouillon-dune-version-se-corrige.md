# ADR-0124 — Le brouillon d'une version se corrige : il ne s'abandonne pas

- **Statut** : Accepté
- **Date** : 2026-11-12
- **Décideurs** : commanditaire (« comment on modifie ou change la version d'une offre ? », « je ne vois même pas dans la console comment on ajoute une version à une offre, comment ça se passe ? »), comité d'architecture
- **Précise** : ADR-0071 §1 (les routes du catalogue), §2 (l'immuabilité d'une version publiée), §3 (les fonctionnalités nommées par le projet)
- **Amende** : —

## Contexte

ADR-0071 pose deux règles du catalogue des offres :

1. une version **publiée** est **figée** — le trigger `plan_version_immutable`
   refuse toute modification de ses conditions ;
2. une offre ne garde **qu'un brouillon** à la fois — l'index unique
   `plan_versions_one_draft` (`plan_id` où `status = 'draft'`).

Entre ces deux règles, un trou. Un brouillon écrit de travers — un prix
faux, une période fausse, une fonctionnalité mal déclarée : l'opérateur ne
peut **ni** le publier (les conditions sont fausses), **ni** en ouvrir un
autre (l'offre a déjà le sien). Il est bloqué, et rien dans le catalogue ne
lui disait qu'un brouillon était un objet jetable.

La question que le commanditaire a posée est simple : *« comment on modifie
ou change la version d'une offre ? »*. La réponse « on ne peut pas » était
juste — et elle était fausse : le geste existait dans le code (la console
écrivait la version), mais il n'était ni écrit, ni atteignable.

## Décision

**Décision 1 — un brouillon se corrige, il ne s'abandonne pas.**

Le brouillon est le brouillon de travail de l'opérateur. Tant qu'il n'est
pas publié, ses conditions se corrigent sur place :

- par la console : le bouton **« Modifier »** de la version, sur la page
  **« Offres »**, et le formulaire est **pré-rempli** avec ce que le
  brouillon porte ;
- par l'API : `PUT /v1/plans/{code}/versions/{n}`, portée `plans:write`.

Le contrat publié est `Core\Billing\Contracts\UpdatePlanVersion` ; il est
distinct de `CreatePlanVersion` parce que le geste est distinct.

**Décision 2 — une version publiée reste figée, et se corrige en publiant
une autre version.**

Rien ne change à la règle d'ADR-0071 §2 : changer un prix reste publier une
nouvelle version. La console ne propose plus **« Modifier »** sur une
version publiée, et l'API répond `409 plan-version-not-editable`.

**Décision 3 — les deux refus du catalogue sont distincts, et la console les
dit en français.**

Une offre qui a déjà une version en préparation n'est pas une offre dont la
version est « publiée et figée » : c'est une offre **occupée**. Le catalogue
les distingue désormais :

| Refus | Exception | Dit à l'opérateur |
| --- | --- | --- |
| La version est publiée | `PlanVersionNotEditable` | « Une version publiée est figée : publiez une nouvelle version pour changer ses conditions. » |
| L'offre a déjà un brouillon | `PlanVersionConflict` | « Cette offre a déjà une version en préparation (version N) : corrigez-la plutôt que d'en ajouter une autre. » |
| Le rang est déjà pris | `PlanVersionConflict` | « La version N existe déjà. » |

La **console** traduit chaque refus du domaine en une phrase qui dit la
suite à faire ; le mot du domaine, qui est anglais et porte les références
internes, reste dans le journal (ADR-0081 §4). La règle vaut pour **tous**
les refus du commerce dans la console, pas seulement pour ceux-ci.

**Décision 4 — la correction remplace les fonctionnalités.**

Une correction n'ajoute pas à côté : les fonctionnalités qui ne sont plus
écrites disparaissent. Un brouillon se corrige comme il s'écrit.

**Décision 5 — le journal garde les valeurs d'avant et d'après.**

La correction d'une version touche un prix : l'entrée d'audit
`billing.plan_version.updated` porte donc les **termes d'avant** et les
**termes d'après**, pas une simple liste de noms de champs. Le journal peut
répondre « le prix était 5 000 avant ».

**Décision 6 — la correction ne bouge pas d'argent.**

`PUT /v1/plans/{code}/versions/{n}` ne porte pas le middleware d'idempotence
(G7) : comme les autres routes du catalogue, elle ne fait pas bouger d'argent
— elle écrit les conditions d'une version qui n'est encore facturée à
personne.

**Décision 7 — la correction ne publie pas.**

Corriger un brouillon le laisse brouillon. La publication reste un geste à
part, qui fige.

## Conséquences

- Le catalogue des offres n'a plus d'impasse : une version écrite de travers
  se corrige, et l'opérateur n'est jamais bloqué par un brouillon qu'il
  ne peut ni publier ni remplacer.
- La console et l'API offerent le même geste, sur le même contrat.
- Le formulaire de correction est pré-rempli : l'opérateur voit ce qu'il
  corrige avant de le corriger.
- Les refus de la console sont en français et sans référence interne : le
  garde-fou `ConsoleScreensSpeakPlainFrenchTest` couvre les écrans, et la
  traduction des refus est désormais dans le code.

## Alternatives écartées

- **Supprimer le brouillon pour en écrire un autre** : le catalogue
  n'accepte qu'un brouillon par offre ; une suppression automatique
  perdrait un travail non publié sans que personne l'ait demandé, et
  l'historique de ce qui a été écrit disparaîtrait.
- **Rendre une version publiée modifiable** : c'est la règle d'ADR-0071 §2,
  et les contrats déjà signés portent les conditions signées.
- **Laisser le trou** : l'opérateur reste bloqué, et la seule issue est de
  modifier la base à la main.

## Vérification

- `packages/billing/tests/Integration/UpdatePlanVersionFlowTest.php` — le
  brouillon se corrige, la version publiée est refusée et ses termes ne
  bougent pas, une offre ou un rang inconnus sont refusés.
- `packages/billing/tests/Integration/UpdatePlanVersionHttpTest.php` — la
  route `PUT` : `200` et les nouveaux termes, `409` sur une version
  publiée, `404` sur une version inconnue, `422` (jamais `500`) sur un prix
  qui n'est pas un entier.
- `tests/Feature/Admin/BillingPlanVersionCorrectionTest.php` — la console
  propose les deux gestes, pré-remplit le formulaire, applique la
  correction, et refuse une version publiée.
- `tests/Feature/Admin/BillingPlanVersionRefusalsTest.php` — le refus
  « l'offre a déjà une version en préparation » est dit en français et sans
  référence interne ; une offre inconnue ramène à la liste des offres ; le
  journal porte le prix d'avant.
