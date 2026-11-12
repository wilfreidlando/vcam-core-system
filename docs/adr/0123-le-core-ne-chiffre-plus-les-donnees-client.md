# ADR-0123 — Le Core ne chiffre plus les données client : il les garde en clair, comme l'application

- **Statut** : Accepté
- **Date** : 2026-11-12
- **Décideurs** : commanditaire (« j'ai dit que on ne chiffre plus rien concernant les données client, rien du tout »), comité d'architecture
- **Dérives concernées** : D3 données/PII
- **Amende** : ADR-0083 §2 et §8, ADR-0070 §1, ADR-0094 §2, ADR-0122 §5
- **Précise** : ADR-0083 §3 (exposition minimale), §4 (audit sans valeurs), §6 (effacement), ADR-0070 §4 (normalisation)

## Contexte

ADR-0083 §2 avait mis en place le chiffrement applicatif de l'identité du
client : `customers.email_ciphertext`, `customers.phone_ciphertext`, un index
aveugle HMAC pour la recherche et l'unicité, une clé versionnée
`PII_ENCRYPTION_KEYS` et une seconde clé `PII_BLIND_INDEX_KEY`. L'instantané
du client figé sur la facture (`invoices.customer_snapshot`) portait la même
chose, en chiffré.

Ce choix était cohérent à l'époque : ADR-0070 traitait le client comme une
donnée personnelle par excellence, et ADR-0081 (modèle de menace) demandait
de réduire ce qui peut fuir.

Mais il crée une **divergence avec la réalité de la plateforme** :

1. **Le Core n'est pas seul détenteur.** L'application métier — l'auto-école,
   et toute application cliente qui suivra — détient déjà le nom, l'e-mail et
   le téléphone de ses propres clients, **en clair**, dans sa base. Le Core en
   est un miroir : il ne protège pas une donnée que l'application expose
   déjà.
2. **La clé est un point de rupture.** `PII_ENCRYPTION_KEYS` et
   `PII_BLIND_INDEX_KEY` sont deux secrets de plus à garder, à faire tourner,
   à ne jamais perdre. Une clé perdue ne rend pas les données illisibles :
   elle les rend *inutilisables* — un client que le Core ne peut plus relire
   est un client que le Core ne peut plus facturer, ni effacer proprement.
3. **Le chiffrement complique sans rien protéger de plus.** La recherche
   passait par un index aveugle, la relecture par un déchiffrement,
   l'effacement par une mise à `NULL`. En clair, la même garantie
   d'unicité par projet tient sur la valeur normalisée, la même recherche
   tient sur un index, le même effacement tient sur une colonne vidée.
4. **Les comptes console ne sont pas chiffrés.** L'e-mail d'un opérateur est
   en clair dans la table `operators` : le Core appliquait donc à ses propres
   comptes une règle qu'il n'appliquait pas à ceux des clients.

Le commanditaire tranche (2026-11-12) : **« on ne chiffre plus rien
concernant les données client, rien du tout »**.

## Décision

**Décision 1 — les données client sont conservées en clair.**

Le nom, l'e-mail, le téléphone, la langue et les valeurs déclarées
(ADR-0122) sont écrits tels quels dans les colonnes du Core. Aucun
chiffrement applicatif, aucun index aveugle, aucune clé personnelle. Le Core
ne garde plus de secret de données client : `PII_ENCRYPTION_KEYS` et
`PII_BLIND_INDEX_KEY` disparaissent de la configuration et du déploiement.

**Décision 2 — la recherche et l'unicité se font sur la valeur normalisée.**

L'e-mail est normalisé avant d'être stocké (minuscules, sans espaces —
ADR-0070 §4) ; l'unicité par projet reste portée par un index unique sur
`(project_id, email)`, et la recherche par contact porte sur la valeur
normalisée. La normalisation fait ce que faisait l'index aveugle — le même
`Ali@Example.CM ` et `ali@example.cm` restent un seul client — sans clé à
garder.

**Décision 3 — le Core n'a plus de chiffreur de données personnelles.**

Le service de chiffrement et l'index aveugle du noyau partagé
(`core/support`) sont retirés : plus rien ne les utilise, et un chiffreur
laissé en place est une tentation. Le noyau partagé garde ce qui n'a rien à
voir avec le chiffrement : la **normalisation** d'une valeur personnelle et
le **masquage** de son affichage.

**Décision 4 — ce qui ne change pas : l'exposition reste minimale.**

Le chiffrement à la source supprimé, les trois autres protections d'ADR-0083
tiennent :

- l'API ne rend l'e-mail et le téléphone qu'à un appelant portant
  `customers:read` (ADR-0083 §3) ;
- la console les **masque** par défaut (`a***@d***.cm`), et le démasquage
  reste une action auditée ;
- le journal d'audit et les journaux techniques ne portent **aucune** valeur
  personnelle (ADR-0083 §4, S-17) ;
- la charge utile des événements n'en porte pas davantage (ADR-0070 §3).

**Décision 5 — l'effacement reste l'effacement.**

`POST /v1/customers/{ref}/erase` vide les colonnes en clair, remplace le nom
par « Client effacé », vide les métadonnées et pose `status = erased` ;
l'instantané de facture est vidé lui aussi (sauf rétention choisie par le
projet). Le grand livre et les factures restent inchangés : ils référencent
un UUID.

**Décision 6 — le passage se fait par une migration qui lit l'ancien format.**

Une migration unique : elle **décrypte** ce qui existe (avec la clé du
déploiement, le temps de la migration), l'écrit en clair, réécrit l'instantané
de facture, puis **supprime** les colonnes chiffrées et les index aveugles.
Après elle, il ne reste dans le code aucun moyen de lire un ancien chiffré —
et plus aucune clé à protéger. Une migration qui ne trouve aucune donnée
chiffrée passe sans clé ; une migration qui en trouve sans clé **échoue
bruyamment**, jamais en silence.

**Décision 7 — les sauvegardes restent chiffrées, mais par le disque.**

ADR-0083 §8 disait que les sauvegardes étaient « illisibles sans les clés ».
C'est faux désormais, et l'affirmation est retirée : les sauvegardes restent
chiffrées **au niveau du stockage** (ADR-0067, dépôt `vcam-infra-deploy`),
mais une sauvegarde lue expose les e-mails et les téléphones en clair.

## Alternatives écartées

- **Garder le chiffrement, ne pas l'appliquer aux valeurs déclarées.**
  Rejeté : c'était l'état d'ADR-0122 §5, et cela maintenait deux régimes pour
  la même donnée. Le commanditaire tranche pour un seul : rien.
- **Garder le chiffreur pour décrypter à la demande.** Rejeté : il n'y a plus
  rien à déchiffrer après la migration, et garder un déchiffreur en
  production, c'est garder la clé.
- **Ne migrer que les nouveaux clients.** Rejeté : deux régimes sur la même
  table, et les anciens clients deviennent illisibles.
- **Chiffrer au niveau du disque ou du volume plutôt que de la colonne.**
  Rejeté : hors périmètre du Core (le stockage et les sauvegardes
  appartiennent à l'infrastructure, ADR-0067) ; et cela nuirait à la
  portabilité de la base.

## Conséquences

- Le Core **n'a plus de secret de données client** : deux clés en moins dans
  le déploiement, un runbook de rotation en moins, et aucun risque de clé
  perdue rendant une population de clients illisible.
- Une fuite de la base expose les noms, e-mails et téléphones **en clair**.
  C'est la contrepartie assumée de la décision : le commanditaire l'a prise en
  connaissance de cause, l'application métier ne chiffrant pas non plus.
- La recherche par contact n'est plus « sans lecture » : elle lit la valeur.
  Elle reste limitée au projet et à `customers:read`.
- `core/support` perd son module de chiffrement ; la normalisation et le
  masquage restent.
- L'affirmation d'ADR-0094 §2 (« le snapshot du client est chiffré ») devient
  fausse : elle est amendée.

## Vérification

- Test : lecture brute de `customers` → l'e-mail y est **en clair**, et il
  n'existe **aucune** colonne `*_ciphertext` ni `*_bidx` (le test de schéma
  le pinne).
- Test : l'unicité de l'e-mail par projet tient toujours, quelle que soit la
  casse.
- Test : l'effacement vide les colonnes en clair et ne touche pas au grand
  livre.
- Test : la facture porte le nom du client en clair et s'imprime.
