# ADR-0127 — La console consulte l'espace du client en lecture seule

- **Statut** : Accepté
- **Date** : 2026-11-17
- **Décideurs** : commanditaire (« est ce qu'un opérateur peut acedder au portail d'un clien en mode lecture ? », « Ajouter un geste "Consulter" »), comité d'architecture
- **Précise** : ADR-0111 (le flux `read`), ADR-0125 (la console ouvre l'espace du client), ADR-0104 (la console sans JavaScript), ADR-0081 S-04
- **Amende** : —

## Contexte

ADR-0111 a créé le flux `read` : une session de portail qui ouvre **les mêmes
pages, avec les mêmes données**, et **aucun geste d'agir**. Le refus est dans
le code qui agit (ADR-0111 §2), pas dans la vue : un POST façonné à la main
échoue exactement comme le bouton qui n'est pas dessiné.

Mais le flux existait **du côté de l'API**. La console, elle, n'avait qu'un
geste : « Ouvrir l'espace du client » (ADR-0125), qui ouvre une session
`manage` — celle dont le lien est destiné au client.

L'opérateur qui voulait **voir** ce que voit le client devait donc passer par
le lien de gestion : un lien qui donne le pouvoir de changer d'offre,
d'annuler, de payer — pour un besoin qui est celui du **support** (« le
client dit qu'il ne voit pas sa facture ») et non celui du client.

## Décision

**Décision 1 — un second geste, dans la console : « Consulter l'espace du
client (lecture seule) ».**

Il ouvre une session `read` et rend le même lien à copier, à usage unique
comme l'autre. Le lien est **pour l'opérateur** : il ouvre l'espace du
client, en lecture seule.

**Décision 2 — le geste de gestion ne change pas.**

« Ouvrir l'espace du client » reste en `manage` : son lien est **le lien du
client**, celui qu'on lui donne pour gérer son abonnement. Remplacer ce geste
par un geste de lecture aurait supprimé le moyen, pour le client, de gérer
son offre — le module refuse toute mutation dans une session `read`.

Les deux gestes cohabitent sur la fiche du client : le second est disponible
même quand un lien vient d'être ouvert.

**Décision 3 — le refus reste dans le code, pas dans l'écran.**

La session `read` porte le refus : chaque geste mutateur du portail —
souscrire, annuler, changer d'offre, renouveler, payer — la refuse, avec une
page qui l'explique (ADR-0111 §2). La console n'ajoute aucun garde-fou
propre : un seul endroit décide, et c'est celui qui agit.

**Décision 4 — l'ouverture reste auditée, avec le flux.**

L'entrée de journal de la console (`portal.session.opened`) porte le flux,
comme celle du module (`portal.session_opened`, ADR-0111 §4) : qui, quand,
et **en lecture seule**. Le lien, lui, n'entre jamais au journal
(ADR-0083 §4).

**Décision 5 — les portées ne changent pas.**

La consultation s'ouvre avec `customers:read`, comme la gestion : ce qui est
demandé, c'est **lire**. Ce qui décide, c'est le module. Un opérateur qui
n'a pas la portée ne voit pas le bouton, et la route reste gardée.

**Décision 6 — la route est soumise au test d'isolation.**

La nouvelle route porte un identifiant : elle figure au manifeste des routes
console (G15). Sous un autre projet, la référence du client n'est **pas
trouvée** — jamais un « interdit », qui confirmerait que la ressource existe
ailleurs (ADR-0081 S-04).

## Conséquences

- L'opérateur peut vérifier une configuration, ou ce qu'un client dit voir,
  sans rien pouvoir changer sur le compte du client.
- Le client garde son lien de gestion : rien n'est retiré à qui en a besoin.
- Une session de consultation est un acte journalisé, comme une session de
  gestion : la trace dit qu'elle était **en lecture seule**.
- La console gagne un bouton de plus sur la fiche client, et une route de
  plus à garder.

## Alternatives écartées

- **Remplacer le geste de gestion par un geste de lecture** : le client
  perdrait le lien qui lui permet de gérer son abonnement. C'est le client
  qui doit agir sur son offre, pas l'opérateur.
- **Laisser le lien de gestion et dire à l'opérateur de ne rien faire** :
  le pouvoir reste ouvert, et l'interface devient la seule barrière — ce
  qu'ADR-0111 §2 refuse.
- **Faire la consultation dans la console elle-même** (un résumé du
  contrat dans la fiche client) : la console ne lit que par les contrats
  publiés des modules, et une fiche qui reproduit l'espace du client
  divergerait de lui — l'écart que le portail, lui, ne peut pas avoir.

## Vérification

- `tests/Feature/Admin/CustomerPortalSpaceConsoleTest.php`
  — la fiche propose le geste ; la session ouverte porte `flow = read` ;
  l'audit de la console et celui du module portent le flux ; le lien ouvert
  mène à l'espace du client, qui se dit « Consultation seule ».
- `packages/portal/tests/Integration/ReadOnlyPortalSessionTest.php`
  — sur une session `read`, aucun geste mutateur ne passe, et rien ne bouge.
