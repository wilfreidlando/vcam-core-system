# ADR-0083 — Données personnelles : minimisation, chiffrement, conservation, effacement

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D3 données/PII · D6 intégrité financière

## Contexte

Le core détient désormais des données personnelles : comptes console (nom,
email), clients des projets (nom, email, téléphone — ADR-0070), contenu de
notifications (déjà expurgé après rétention), destinataires de versements
(ADR-0079). Une fuite de base ou de sauvegarde exposerait les clients de
**toutes** les organisations.

L'objectif ici est **technique** : réduire ce qui peut fuir, rendre ce qui
fuit inexploitable, et permettre d'effacer une personne sans casser le grand
livre.

## Décision

### 1. Inventaire et minimisation

| Donnée | Où | Nécessaire pour |
|---|---|---|
| Nom affiché, email, téléphone du client | `customers` | portail, factures, codes de connexion |
| Contact figé sur facture | `invoices.customer_snapshot` | document émis |
| Numéro de destination d'un versement | `payouts` | appel au prestataire |
| Nom et email d'un compte console | `operators` | connexion, audit |

Rien d'autre : pas de pièce d'identité, pas d'adresse postale, pas de date de
naissance, pas de moyen de paiement stocké. Toute colonne personnelle
nouvelle exige un ADR (liste blanche de colonnes testée, ADR-0070).

### 2. Chiffrement au repos et index aveugles

- Chiffrement applicatif (AES-256-GCM via le chiffreur du framework) avec une
  clé **dédiée** `PII_ENCRYPTION_KEY`, distincte de `APP_KEY`, **versionnée**
  (le préfixe du chiffré indique la version ; plusieurs clés de déchiffrement
  acceptées pendant une rotation ; re-chiffrement par job).
- Recherche exacte et unicité par **index aveugle** :
  `HMAC-SHA256(PII_BLIND_INDEX_KEY, valeur normalisée)`, clé distincte, tronqué
  à 32 octets.
- Les clés ne sont jamais dans le dépôt ni dans la base ; elles viennent de
  l'environnement de déploiement (ADR-0064).

### 3. Exposition minimale

- API : email et téléphone renvoyés seulement avec `customers:read`.
- Console : masqués par défaut (`a***@d***.cm`, `+237 6** ** ** 12`) ;
  démasquage = action auditée.
- Webhooks : **aucune** donnée personnelle dans les charges utiles ; des
  identifiants seulement (ADR-0070 §3).
- Journaux techniques : expurgés (S-17).

### 4. Le journal d'audit ne contient pas de valeurs personnelles

Pour les ressources qui portent des données personnelles, l'audit enregistre
**quels champs** ont changé (`{"changed": ["email", "phone"]}`) et les
identifiants, **jamais les valeurs**. L'effacement n'a donc pas à réécrire
l'audit, qui reste strictement immuable (S-16).

### 5. Conservation paramétrée, purge planifiée

| Classe | Durée par défaut | Fin de vie |
|---|---|---|
| Contenu de notification | 90 jours | expurgé (existant) |
| Livraisons de webhook | 90 jours | supprimées (existant) |
| Codes de connexion portail, sessions | 7 jours après expiration | supprimés |
| Journal d'usage | 24 mois | partitions détachées puis supprimées |
| Clients archivés (projet archivé) | 36 mois | effacés (§6) |
| Grand livre, factures | conservés | jamais supprimés ; identités effaçables |
| Journal d'audit | 5 ans | partitions archivées |

Les durées sont des réglages de plateforme (et, pour certaines, de projet) ;
la purge est un job quotidien idempotent.

### 6. Effacement d'un client

`POST /v1/customers/{ref}/erase` (projet) ou action console (`org_admin`) :

1. `email_*`, `phone_*` mis à `NULL`, `display_name` remplacé par
   « Client effacé », `metadata` vidé, `status = erased`, `erased_at` posé.
2. `invoices.customer_snapshot` : effacé aussi, **sauf** si le projet a choisi
   `billing_settings.retain_invoice_identity = true` (la facture garde alors le
   nom figé, chiffré).
3. Abonnements non finaux → `canceled` ; sessions de portail révoquées ;
   aucun code ne peut plus être envoyé.
4. Grand livre, factures, abonnements : **inchangés**. Ils référencent
   `customer_id`, un UUID sans signification : le grand livre reste lisible et
   équilibré.
5. Irréversible ; audité ; événement `customer.erased`.

Une facture encore due n'empêche pas l'effacement de l'identité ; elle reste
due (la créance subsiste au grand livre).

### 7. Export d'un client

`GET /v1/customers/{ref}/export` : toutes les données que le core détient sur
ce client (fiche, abonnements, factures, paiements, usage), en JSON, au format
du contrat public.

### 8. Sauvegardes

Chiffrées (sauvegardes S3, ADR-0067 du dépôt `vcam-infra-deploy`) ; les données
personnelles y sont déjà chiffrées au niveau applicatif, donc illisibles sans
les clés, qui ne sont pas sauvegardées avec la base.

## Alternatives écartées

- **Chiffrement du disque seulement.** Rejeté : ne protège ni d'une fuite par
  requête SQL, ni d'une sauvegarde copiée.
- **Hachage simple de l'email pour la recherche.** Rejeté : réversible par
  dictionnaire ; l'index aveugle à clé ne l'est pas sans la clé.
- **Supprimer la ligne client.** Rejeté : casse les références du grand livre
  et des factures.
- **Rédiger a posteriori le journal d'audit.** Rejeté : rend l'audit mutable ;
  ne pas y écrire les valeurs est plus simple et plus sûr.

## Conséquences

- `support` fournit un service de chiffrement et d'index aveugle **sans
  persistance** (compatible G3d).
- Les tests de schéma vérifient que les colonnes personnelles connues sont
  bien des colonnes `*_ciphertext` / `*_bidx`.
- La rotation des clés de chiffrement est un runbook documenté.

## Vérification

- Test : lecture brute de `customers` → aucun email en clair.
- Test : effacement → balance de vérification inchangée, factures intactes,
  aucune donnée personnelle restante (hors option de rétention).
- Test : entrée d'audit d'une modification de client → aucune valeur
  personnelle.
