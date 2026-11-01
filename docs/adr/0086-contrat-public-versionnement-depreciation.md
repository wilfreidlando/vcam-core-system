# ADR-0086 — Le contrat public : versionnement, évolution sans rupture, dépréciation

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D5 contrat · D7 organisationnelle
- **Complète** : ADR-0028 (versionnement par préfixe d'URL — sa fenêtre de dépréciation, laissée « à confirmer », est fixée ici)

## Contexte

ADR-0028 a fixé le versionnement par préfixe d'URL (`/v1`) et G9 détecte en CI
toute rupture d'`openapi/v1.json` (`oasdiff breaking`). Restent non tranchés :
ce qui est autorisé sans nouvelle version, combien de temps une version
majeure vit après la suivante, comment les intégrateurs sont prévenus, et
comment les **événements** suivent le même contrat. Le plan d'administration
(`openapi/admin.json`) n'est pas couvert par G9.

## Décision

### 1. Une seule notion de version : la majeure, dans l'URL

`/v1`, puis `/v2` le jour où une rupture est inévitable. Pas d'en-tête de
version (ADR-0028 l'a écarté). Le contrat d'une majeure couvre ensemble :
routes, schémas, codes et formes d'erreur (RFC 7807), paramètres, **et
événements**.

### 2. Ce qui est permis dans une majeure

Sans nouvelle version, et donc sans délai :

- ajouter une route, un champ de réponse, un champ de requête **optionnel**,
  un type d'événement, un champ dans une charge utile d'événement ;
- ajouter une **valeur** à une énumération de réponse — **à condition** que
  l'énumération soit documentée comme « ouverte » (le guide impose aux
  intégrateurs de tolérer une valeur inconnue). Les énumérations ouvertes sont
  marquées `x-extensible-enum: true` dans l'OpenAPI.

Interdit dans une majeure (G9 le bloque) : retirer ou renommer un champ, une
route, une valeur ; rendre obligatoire un champ optionnel ; changer un type,
une sémantique, un code d'erreur.

### 3. Durée de vie d'une majeure

- Quand `/v(n+1)` sort, `/vn` est servie **12 mois** au moins.
- Annonce : changelog, événement `api.deprecation_scheduled` aux projets
  concernés, bandeau dans la console sur les projets qui appellent encore
  `/vn` (mesuré par les métriques).
- Pendant la fenêtre, les réponses de `/vn` portent `Deprecation` (RFC 9745)
  et `Sunset` (RFC 8594) avec la date de fin.
- Après la date : `410 Gone` avec le lien du guide de migration — jamais un
  `404` trompeur. Aucune coupure sans annonce préalable ; une erreur de date
  repousse la coupure, jamais l'inverse.

### 4. Événements

- L'enveloppe d'événement gagne `api_version` (« v1 »). Un endpoint de webhook
  est abonné à **une** majeure ; pendant une fenêtre de transition, un projet
  peut enregistrer un second endpoint sur la majeure suivante.
- Les charges utiles d'une majeure suivent les règles du §2.

### 5. Changelog

`CHANGELOG-API.md`, généré à chaque **tag de publication** par
`oasdiff changelog` entre le contrat publié précédent et le nouveau, puis
complété à la main (contexte, migration). Une cible de CI conditionnée au tag
échoue si le changelog n'est pas à jour.

### 6. Plan d'administration

`openapi/admin.json` est versionné et soumis à G9 comme le contrat public :
une organisation tierce automatisera aussi la console.

## Alternatives écartées

- **Versions datées par en-tête.** Rejeté par ADR-0028 et inutile à cette
  échelle ; la majeure dans l'URL est lisible et testable.
- **Fenêtre de dépréciation pour les ajouts.** Rejeté : un ajout ne casse rien
  et n'a rien à déprécier.
- **Versionner les événements séparément.** Rejeté : deux axes de version à
  gérer pour l'intégrateur.

## Conséquences

- G9 étendu à `openapi/admin.json`.
- Le guide d'intégration publie les règles de tolérance (champs et valeurs
  inconnus ignorés).
- ADR-0028 voit sa question ouverte close.

## Vérification

- CI : `oasdiff breaking` sur les deux contrats ; test qu'une énumération non
  marquée `x-extensible-enum` ne gagne pas de valeur sans rupture déclarée.
- Test : une route d'une majeure retirée renvoie `410` avec le lien de
  migration.
