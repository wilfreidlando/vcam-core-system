# ADR-0132 — L'import en lot porte la forme déclarée en vigueur

- **Statut** : Accepté
- **Date** : 2026-11-22
- **Décideurs** : commanditaire (« modifie aussi la forme de l'import »), comité d'architecture
- **Dérives concernées** : D4 intégrité des données
- **Précise** : ADR-0122 §5 (la clé déclarée remplace le champ du Core), ADR-0122 §9 (l'import en lot), ADR-0130 §1 (les valeurs déclarées sont écrites avant l'événement)
- **Amende** : ADR-0122 §9 (le fichier importé ne porte que l'identité du Core), ADR-0125 §5 (l'en-tête exact du modèle)

## Contexte

ADR-0122 §9 décrit l'import en lot des clients qu'une application avait
déjà : l'application exporte ses clients, l'opérateur dépose le fichier,
le Core crée les clients et pousse l'événement. Le fichier ne portait que
l'identité du Core — `external_ref`, `display_name`, `email`, `phone` — et
l'import **écartait** toute valeur déclarée.

C'était cohérent à l'époque : la déclaration de forme (ADR-0122 §2)
venait d'arriver, la console la rendait (ADR-0130), mais l'import, lui,
restait sur le contrat fixe du Core. Deux conséquences en découlaient :

1. **Les valeurs déclarées se perdaient à l'import.** Une application
   qui déclarait une localisation, une année, un responsable, exportait
   ses clients : le Core créait les clients, mais **sans** les valeurs
   que l'application avait déclarées. L'application les avait déjà dans
   sa base, et le Core ne les savait pas — l'écart que la déclaration
   devait supprimer renaissait par la porte de l'import.
2. **Le modèle téléchargé mentait.** Le modèle downloadable portait
   quatre colonnes, alors que la forme en vigueur en imposait d'autres :
   l'opérateur remplissait le modèle, le dépôtait, et le fichier était
   **refusé** — le lecteur exigeant un en-tête différent de celui du
   modèle, pour la même raison qu'il le suivait.

Le même esprit que la validation de la console (ADR-0131 §5) s'applique
à l'import : ce que le Core exige et ce qu'il montre ne peuvent pas
diverger.

## Décision

**Décision 1 — le fichier importé porte la forme en vigueur.**

Les colonnes du fichier sont, dans cet ordre :

1. `external_ref` — la référence, toujours présente ;
2. les colonnes d'identité du Core (`display_name`, `email`, `phone`) —
   **seulement** celles que la déclaration ne remplace pas (ADR-0122 §5) ;
3. une colonne par clé déclarée, dans l'ordre de la déclaration.

Le début du fichier est donc toujours le même, et les valeurs propres à
l'application viennent après.

**Décision 2 — sans déclaration acceptée, le fichier ne change pas.**

Le projet qui n'a rien déclaré continue d'échanger les quatre colonnes
que le Core a toujours lues. Rien n'est cassé par la décision 1.

**Décision 3 — les valeurs déclarées sont écrites avec le client.**

Elles voyagent avec la commande d'upsert, dans la **même transaction**,
avant que `customer.created` ne parte (ADR-0130 §1). Une clé déclarée
dont la source nomme un champ d'identité du Core alimente ce champ —
elle n'est pas écrite deux fois.

**Décision 4 — une ligne dont une valeur déclarée ne tient pas est
refusée.**

Trois refus, dits en français dans le rapport de l'import : une valeur
obligatoire et vide, une valeur plus longue que la longueur annoncée, une
valeur qui n'est pas du type annoncé (un nombre entier annoncé, un texte
reçu). La ligne est refusée ; les autres lignes sont importées.

**Décision 5 — le modèle téléchargé suit la forme en vigueur.**

Le lecteur et le modèle lisent la **même source** : un en-tête ne peut
pas être exigé par l'un et proposé par l'autre. Le modèle téléchargé
s'importe tel quel, dans un projet déclaré comme dans un projet sans
déclaration.

## Alternatives écartées

- **Garder les quatre colonnes, et laisser les valeurs déclarées de
  côté.** Rejeté : l'import perdrait exactement ce que la déclaration
  doit porter. L'application devrait redéclarer ses clients après les
  avoir importés — le travail fait deux fois, et le résultat divergerait.
- **Accepter un fichier qui porte un sous-ensemble des colonnes.** Rejeté :
  l'en-tête est un **contrat** (ADR-0122 §9). Un fichier qui ne porte
  pas la forme en vigueur est ambigu : on ne sait pas ce que l'application
  a voulu dire. Il est refusé, et le modèle dit quelle forme il attend.
- **Lire les valeurs déclarées par position plutôt que par nom.** Rejeté :
  un fichier dont l'ordre change serait lu dans le désordre, et le client
  serait créé avec les valeurs d'un autre. Le nom de la colonne est la
  seule chose stable.
- **Écrire les valeurs déclarées dans l'audit.** Rejeté : l'audit ne
  porte aucune valeur personnelle (ADR-0083 §4) ; il compte les lignes.

## Conséquences

- Une application qui déclare des clés doit **exporter** ces colonnes :
  le contrat d'export suit la déclaration. C'est le contrepartie
  assumée d'une déclaration.
- Un projet sans déclaration ne change rien ; un projet déclaré voit son
  modèle se mettre à jour.
- L'écran d'import dit le nombre de colonnes et explique chacune
  d'elles, dans les mots de l'activité — jamais les noms techniques.
- Le refus d'une valeur déclarée est une raison de plus pour qu'une
  ligne soit refusée ; le rapport les compte, ligne par ligne.

## Vérification

- Test : le modèle téléchargé porte l'en-tête de la forme en vigueur,
  clés déclarées comprises, et il s'importe tel quel.
- Test : un fichier qui ne porte pas la forme en vigueur est refusé.
- Test : les valeurs déclarées de la ligne importée sont écrites avec
  le client (`customer_declared_attributes`).
- Test : une valeur déclarée obligatoire et vide, ou d'un autre type,
  refuse la ligne — et le motif nomme le champ.
- Test : une clé déclarée qui porte le nom du client remplace la colonne
  `display_name`, et le nom du client est celui de la clé.
