# ADR-0113 — Le guide de l'opérateur est dans la console

- **Statut** : Accepté — **amendé le 2026-10-31, le jour même**, à la demande du commanditaire : les captures d'écran sont abandonnées (décision 3)
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (il a demandé une documentation visuelle, puis a renuncié aux captures), comité d'architecture
- **Dérives concernées** : D3 utilisabilité · D5 contrat
- **Précise** : ADR-0075 (le portail, l'espace du client), ADR-0104 (la console sans JavaScript)

## Contexte

Le produit a un manuel : dix chapitres, environ cent cinquante kilo-octets, rangés **par
sujet** (la connexion, les projets, les clés, le commerce, l'argent…). C'est un bon
document de référence, et c'est un mauvais guide : le commanditaire l'a constaté —
« moi même je ne connais plus » — et il a raison.

Un manuel rangé par sujet suppose que le lecteur sait déjà **ce qu'il cherche**.
Celui qui doit relancer un client en retard ne cherche pas « le chapitre 5 » : il
cherche « comment je relance un client en retard ». La question qu'il se pose est une
**situation**, pas un sujet.

Il y a trois autres problèmes, tous vus dans le manuel lui-même :

1. **Les écrans parlent technique.** Une page d'indicateurs dit à l'opérateur « la
   relance d'un client passe par son portail ou par votre propre message
   (ADR-0098) ». Un numéro de décision ne dit pas quoi faire ; il dit qu'un
   ingénieur a déjà décidé. La lecture d'un écran doit être une consigne, pas un
   renvoi à l'architecture.
2. **Le manuel est technique.** Il présente des API, des portées, des en-têtes. Le
   lecteur visé — un opérateur qui prend la console en main, un commercial qui
   répond à un client sur ses factures — n'a aucun usage de tout cela.
3. **Le manuel est invisible.** Il est dans le dépôt. L'opérateur, lui, est dans la
   console, huit heures par jour. Un guide qui n'est pas là où on travaille n'est
   pas lu.

## Décision

**Décision 1 — le guide est dans la console, à `/admin/guide`.**

Une entrée « Guide » dans la barre latérale ouvre la page des parcours. Chaque
parcours est une page à part, adressable, partageable, imprimable. Le guide est là
où l'opérateur travaille déjà ; le lien y reste permanent, et non une chose à
retrouver dans un dépôt.

**Décision 2 — une seule source : le Markdown, rendu par la console.**

Les parcours sont écrits en Markdown dans `docs/manuel/parcours/`. La console les
rend (CommonMark, `league/commonmark`, déjà présent dans l'image via Laravel). Le
dépôt et la console montrent **le même texte** ; il ne peut pas y en avoir un qui
dérive de l'autre. Le guide se relit, se corrige et se versionne comme du code.

**Décision 3 — pas de captures d'écran : le guide se lit sans images.**

Le commanditaire a demandé un guide « visuel », puis a mesuré le coût : une capture
se refait à chaque retouche d'un écran, elle vieillit en silence, et elle ne dit
pas ce que l'écran ne dit pas. « Si la fonctionnalité est déjà expliquée dans le
manuel, ça va. »

Le guide est donc **un texte** : des tableaux, des encadrés, une navigation par
situation. Il décrit l'écran **avec des mots précis** — « la colonne *Payé* montre
le montant déjà réglé, en face du total » — et non avec une image. Une capture ne
prouvait rien de plus qu'une phrase juste ; elle coûtait une image à refaire.

**Décision 4 — le rendu n'autorise pas le HTML brut.**

CommonMark est configuré en `html_input: strip`. Le Markdown vient de nous, mais le
jour où le guide devient éditable dans la console, une balise ne doit pas pouvoir
s'injecter. Le guide produit du Markdown, pas du HTML.

**Décision 5 — le guide est rangé par situation, pas par sujet.**

Dix-neuf parcours, cinq familles : *entrer et gérer les accès*, *vendre et
encaisser*, *suivre et reprendre l'argent*, *brancher et surveiller les
livraisons*, *extraire et piloter*. L'entrée du guide est une table « je veux
faire quoi → parcours », jamais une table des matières de rubriques.

**Décision 6 — la documentation technique reste à part, et hors de la console.**

L'API, les choix de conception, les cas d'usage avancés et les cas limites
restent dans `docs/manuel/reference/` (et, pour l'API, dans `docs/integration/`).
Ils ne sont pas rendus dans la console : ils sont écrits pour des techniciens, et
un opérateur qui les ouvre n'y trouvera pas ce qu'il cherche.

**Décision 7 — le guide est protégé par un garde-fou.**

Un test vérifie que **chaque libellé d'interface cité par un parcours est un
libellé que le produit affiche vraiment** : si le bouton « Nouvelle facture »
s'appelle « Créer une facture » et que le guide n'a pas suivi, la build échoue.
Un test vérifie aussi que chaque parcours porte son front matter complet et que
l'index les liste tous. Le guide ne peut pas mentir en silence.

## Alternatives écartées

**Des captures d'écran.** — Rejeté : le commanditaire a mesuré le coût et l'a
trouvé trop lourd. Une capture se refait à chaque retouche d'écran, elle vieillit
en silence, et une phrase juste fait le même travail. *C'est l'alternative que le
commanditaire avait d'abord demandée (« un guide visuel »), puis a écartée
lui-même.*

**Un site HTML séparé, hébergé ailleurs.** — Rejeté : il vit dans un autre
dépôt, il n'est pas versionné avec le code, il ne se met pas à jour au déploiement,
et il faut s'y rendre. Le guide d'un outil se trouve dans l'outil.

**Un PDF, exporté une fois.** — Rejeté : il périme au premier mot changé, et il ne
peut pas être lu à l'écran pendant qu'on cherche.

**Des pages Blade écrites à la main pour chaque parcours, en plus du Markdown.** —
Rejeté : deux sources, donc une divergence. Le Markdown est la source ; la console
le rend.

**Le guide dans le portail client.** — Rejeté : le portail est l'espace du client
(ADR-0075). Il a déjà ses propres textes, écrits pour le client. Le guide est celui
de l'opérateur, et il vit dans l'outil de l'opérateur.

**Ranger le guide par sujet, en réécrivant les dix chapitres.** — Rejeté : cela
produirait un document de référence réécrit, toujours rangé par sujet. Le problème
n'est pas la qualité des chapitres, c'est leur **ordre de lecture**.

## Conséquences

**Ce qui est gagné.** — Un guide que l'opérateur atteint en un clic depuis l'outil
qu'il utilise, rangé par la question qu'il se pose, et dont les mots ne peuvent pas
diverger des écrans.

**Ce qui est engagé.**

- `league/commonmark` devient une dépendance **explicite** du projet (elle est
  déjà dans l'image comme dépendance de Laravel ; l'utiliser sans la déclarer
  serait compter sur le hasard).
- Le guide devient une **charge à tenir** : chaque écran modifié impose de mettre
  son texte à jour. C'est le prix de la vérité — et c'est le garde-fou de la
  décision 7 qui le rend tenable.
- Les mots du guide doivent être **vérifiés** : le test de la décision 7 échoue si
  le guide cite un libellé que le produit n'affiche plus. Un parcours écrit à
  l'arrache, avec des libellés inventés, ne passe pas.

**Point de réévaluation.** — Si le guide doit devenir éditable par un non-développeur
(une équipe de rédaction qui publie ses propres parcours), le Markdown cesse d'être
la source : il faut alors un dépôt de contenu, une table de parcours, et une
migration. La décision 4 (pas de HTML brut) est précisément ce qui rendrait cette
migration possible sans rouvrir une faille.
