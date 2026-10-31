# ADR-0113 — Le guide de l'opérateur est dans la console

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (il a demandé une documentation visuelle), comité d'architecture
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
   engineer a déjà décidé. La lecture d'un écran doit être une consigne, pas un
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
où l'opérateur travaille déjà ; l'y faire?vient un lien permanent, et non une
chose à retrouver dans un dépôt.

**Décision 2 — une seule source : le Markdown, rendu par la console.**

Les parcours sont écrits en Markdown dans `docs/manuel/parcours/`. La console les
rend (CommonMark, `league/commonmark`, déjà présent dans l'image via Laravel). Le
dépôt et la console montrent **le même texte** ; il ne peut pas y en avoir un qui
dérive de l'autre. Le guide se relit, se corrige et se versionne comme du code.

**Décision 3 — les captures sont versionnées avec le code.**

Elles vivent dans `docs/manuel/parcours/images/` et sont servies par la console.
Une capture périmée n'est pas un détail : c'est un défaut de la documentation. Les
parcours nomment l'écran qu'ils décrivent, et le nom du bouton dans le texte est le
nom du bouton **tel qu'il est écrit à l'écran** — la documentation et l'interface
sont donc le même objet à garder d'accord.

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

Un test vérifie que **chaque chemin d'écran nommé dans le guide est bien routé**,
et que chaque parcours du guide existe. Une capture qui montre un écran
disparu, ou un parcours qui parle d'une page qui n'existe plus, fait échouer la
build — le guide ne peut pas mentir en silence.

## Alternatives écartées

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
qu'il utilise, rangé par la question qu'il se pose, et dont les captures ne
peuvent pas mentir.

**Ce qui est engagé.**

- `league/commonmark` devient une dépendance **explicite** du projet (elle est
  déjà dans l'image comme dépendance de Laravel ; l'utiliser sans la déclarer
  serait compter sur le hasard).
- Les images du guide entrent dans l'image Docker. Elles doivent rester
  raisonnables (format PNG, un poids léger, pas de GIF).
- Le guide devient une **charge à tenir** : chaque écran modifié impose de mettre
  sa capture et son texte à jour. C'est le prix de la vérité.
- Le garde-fou du parcours (décision 7) impose que le guide ne cite que des
  écrans existants — donc que le guide soit écrit **avec** le code, pas après.

**Point de réévaluation.** — Si le guide doit devenir éditable par un non-développeur
(une équipe de rédaction qui publie ses propres parcours), le Markdown cesse d'être
la source : il faut alors un dépôt de contenu, une table de parcours, et une
migration. La décision 4 (pas de HTML brut) est précisément ce qui rendrait cette
migration possible sans rouvrir une faille.
