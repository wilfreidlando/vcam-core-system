# ADR-0082 — Clés d'API à portée limitée, rotation sans coupure, secrets de webhook rotables

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D2 couplage · D5 contrat · D7 organisationnelle
- **Amende** : ADR-0066 (« une seule clé par projet » devient « plusieurs clés nommées par projet »), ADR-0044 (toute clé reçoit `ApiKeyScopes::ALL`)

## Contexte

Vérifié dans le code et les migrations :

- contrainte `api_keys_one_per_project` : **une seule** clé active par projet ;
  la remplacer coupe l'application ;
- `ApiKeyScopes::ALL` liste **10** portées et toute clé les reçoit toutes : un
  serveur qui n'a besoin que de lire les paiements détient aussi le droit de
  verser ;
- un endpoint de webhook a **un** secret, sans rotation possible ;
- aucune restriction d'origine réseau.

Avec des organisations tierces, des portails et des versements, une clé
unique et toute-puissante est le maillon faible du système.

## Décision

### 1. Plusieurs clés nommées par projet

- Jusqu'à **10** clés actives par projet (réglable par la plateforme), chacune
  avec un **nom**, une liste de **portées**, une restriction réseau
  optionnelle, une expiration optionnelle, `last_used_at` et `last_used_ip`.
- La contrainte `api_keys_one_per_project` est remplacée par un compteur
  vérifié à l'émission.
- Format inchangé (préfixe visible + secret haché). Le préfixe `pk_` reste
  reconnaissable par les outils de détection de secrets.

### 2. Portées choisies à l'émission

Portées ajoutées aux 10 existantes : `customers:read/write`,
`plans:read/write`, `subscriptions:read/write`, `invoices:read/write`,
`usage:read/write`, `portal:write`, `payouts:read/write`, `exports:write`
(ADR-0088).

Modèles proposés dans la console pour éviter l'erreur :

| Modèle | Portées |
|---|---|
| Serveur complet | toutes sauf `payouts:write` |
| Lecture seule | toutes les `:read` |
| Portail uniquement | `portal:write`, `customers:read` |
| Versements | `payouts:read`, `payouts:write` (clé séparée, conseillée avec restriction réseau) |

`*` reste possible pour la compatibilité, affiché comme « accès total » avec
un avertissement. Les clés existantes conservent leurs portées actuelles ; les
nouvelles portées ne leur sont **pas** ajoutées automatiquement (moindre
privilège), sauf demande explicite du projet.

### 3. Rotation

- **Rotation guidée** : créer une nouvelle clé, déployer, puis révoquer
  l'ancienne. Option « révoquer l'ancienne dans N jours » (1 à 30, défaut 7) :
  l'ancienne reçoit `expires_at` et reste valide jusque-là.
- **Révocation d'urgence** : immédiate, à tout moment, motif obligatoire,
  auditée ; possible même pendant une période de coexistence.
- L'idempotence reste portée par `(project_id, clé d'idempotence)` (vérifié :
  `IdempotencyStore`), donc indépendante de la clé utilisée.

### 4. Restriction réseau (optionnelle, stricte une fois activée)

Liste de blocs CIDR par clé. Appel hors liste → `403 key-origin-not-allowed`,
journalisé, alerte si répété. L'adresse prise en compte est celle déterminée
par la configuration des proxys de confiance (pas l'en-tête brut
`X-Forwarded-For`).

### 5. Secrets de webhook : rotation avec double signature

- `POST /v1/webhook-endpoints/{id}/rotate-secret` (`webhooks:write`) génère un
  nouveau secret, affiché **une fois**.
- Pendant **24 h** (réglable 1 à 72 h), chaque livraison porte **deux**
  signatures : `X-Signature` (format inchangé, calculée avec le **nouveau**
  secret) et `X-Signature-Previous` (même format, avec l'**ancien** secret).
  Un destinataire qui n'a pas encore changé de secret vérifie
  `X-Signature-Previous` ; un vérificateur existant qui ne lit que
  `X-Signature` n'est pas cassé une fois son secret mis à jour. Ensuite
  l'ancien secret est effacé et l'en-tête additionnel disparaît.
- Révocation immédiate de l'ancien secret possible (motif, audit).

### 6. Identifiants des applications prestataires

Saisis par `org_admin` (ou la plateforme) dans la console, **écriture
seule** : jamais réaffichés, chiffrés au repos, remplaçables. Chaque
remplacement est audité.

## Sécurité

S-03 (moindre privilège), S-07 (affichage unique), S-08 (stockage), S-12
(limite de débit par clé, ADR-0085), S-20 (alerte sur création de clé, usage
depuis une nouvelle IP pour une clé à restriction, rotation d'urgence).

## Alternatives écartées

- **Garder une clé par projet, avec rotation primaire/secondaire.** Rejeté :
  impose le même jeu de droits à tous les composants d'un projet ; plusieurs
  clés nommées couvrent la rotation **et** la séparation des droits.
- **Rotation automatique périodique.** Rejeté : coupe au pire moment ; la
  rotation guidée avec échéance donne le même résultat sans coupure.
- **OAuth 2.0 client credentials.** Écarté pour l'instant : utile pour des
  intégrations tierces au nom d'un projet ; les clés à portée suffisent aux
  serveurs des projets. Point d'extension (ADR-0090).

## Conséquences

- Migration : la contrainte d'unicité est retirée ; chaque clé existante est
  nommée « clé principale ».
- `ProjectContext` porte l'identifiant de la clé (traçabilité dans l'audit et
  les métriques).
- Le guide d'intégration documente rotation, portées et double signature.

## Vérification

- Test : clé sans `payouts:write` → `403` sur `POST /v1/payouts`.
- Test : clé avec `expires_at` dépassé → `401`.
- Test : livraison pendant la rotation → deux signatures valides ; après 24 h,
  une seule.
- Test : origine hors CIDR → `403 key-origin-not-allowed`.
