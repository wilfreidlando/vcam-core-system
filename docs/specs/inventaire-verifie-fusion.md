# Inventaire vérifié — ce que le core sait, ce que la plateforme transitoire apporte, ce qui est mort

> **Document de transition** (ADR-0068 §6). Chaque constat a été vérifié par
> lecture du code le 2026-10-24 ; la source est indiquée.

- **Date** : 2026-10-24 · **Version** : 2.0 · **Statut** : **ratifié** le 2026-10-27

## 1. Le core aujourd'hui (à ne pas reconstruire)

Laravel 13 / PHP 8.4 / PostgreSQL 18 / Valkey / Horizon / Sanctum ; monolithe
modulaire, paquets `core/*` sous `packages/`.

| Module | État | Ce qui est livré |
|---|---|---|
| `support` | livré | `Money`, `Clock` (+ horloges de test), `Identifier` UUID v7, `UnitOfWork`, RFC 7807, idempotence HTTP `(project_id, clé)`, `Integration` = unique sortie HTTP (G6c) |
| `audit` | livré | journal append-only **par convention** (aucun trigger en base), `AuditQuery` |
| `identity` | livré | organisations et projets (`status` texte, défaut `active`, sans contrainte), **une** clé par projet (`api_keys_one_per_project`), 10 portées toutes attribuées à chaque clé (`ApiKeyScopes::ALL`), opérateurs avec 5 rôles fixes, MFA TOTP |
| `parties` | livré | tiers sans identité, création paresseuse, garde-fou anti-PII (G4) |
| `ledger` | livré | double entrée, somme nulle par trigger différé, écritures immuables par trigger, soldes matérialisés ; comptes système `clearing` (niveau projet), `wallet`, `withdrawal` ; lecture **par compte** uniquement |
| `wallet` | livré | crédit (débit `clearing` / crédit `wallet`), débit, transferts multi-jambes à somme nulle, sorties enregistrées |
| `payment` | livré | canal canonique, routage `(canal, projet) → prestataire`, une application prestataire par projet, MyCoolPay (`payin`, `paylink`, `checkStatus`), callback authentifié IP + signature ; **le montant confirmé n'est pas comparé** ; **un paiement sans wallet n'écrit rien au grand livre** |
| `events` | livré | outbox transactionnelle, relais unique **chaque minute** |
| `webhook` | livré | endpoints par projet, HMAC de `timestamp.corps`, tolérance 5 min documentée, retry/backoff, DLQ, rejeu ; **un** secret par endpoint |
| `notification` | livré | cascade de secours, WAHA, sessions enregistrées par projet, `client_reference` ; la notification est créée `queued` **avant** l'appel au prestataire |
| `admin` | livré | console opérateur : audit, DLQ, réconciliation de statut, listings, organisations, projets, applications prestataire, opérateurs ; **aucun cloisonnement par organisation** |
| `billing`, `developer-platform`, `fees`, `storage` | scaffolds | hors production (ADR-0048) |

## 2. La plateforme transitoire (`v_cam-services-center`)

Laravel 11 / PHP 8.2 / MariaDB 10.7.

| Brique | Constat vérifié | Source |
|---|---|---|
| Secret d'application | chiffré au repos (`Crypt::encryptString`), déchiffré correctement par le middleware d'API | `app/Models/Application.php:30`, `app/Http/Middleware/ApiAuthMiddleware.php:46` |
| SSO par jeton à usage unique | fonctionnel : aléa, SHA-256 stocké, 5 min, usage unique ; ouvre une session **de la plateforme** | contrôleurs SSO |
| SSO JWT | **cassé** : `hash_equals` sur le secret **chiffré** → refus systématique ; HS256 sur secret partagé, refresh 30 j | `app/Http/Controllers/SsoController.php:37,95,157` |
| Synchronisation des clients | `POST {app_url}/api/clients/sync` — route **inexistante** côté application (qui expose `POST /api/v1/tenants`, autres en-têtes, autre corps) | `app/Services/ClientSyncService.php:44` |
| Encaissement | appel direct à l'agrégateur ; callback **GET non signé** qui active les abonnements | `routes/api.php:43` |
| Paiements | table sans montant, statut ni devise ; blob de la requête | migration `paiements` |
| Quotas | décrément manuel par routes dédiées ; colonnes nulles dans `parametres` | contrôleurs client |
| Opérateurs | mot de passe par défaut `12345678` pour tous ; code 2FA stocké en clair | `app/Http/Controllers/admin/UserController.php:44` |
| Notifications | proxy vers e-mail ; service factice (jetons en dur) ; intégration Green API dont le paquet est **absent** du `composer.lock` | `composer.lock`, services |
| Policies | 4 déclarées, jamais enregistrées | `app/Policies/` |

## 3. L'application de référence (gestion d'auto-écoles)

| Élément | Constat vérifié | Source |
|---|---|---|
| Branchement au core | **déjà branchée** pour WhatsApp : `POST /v1/notifications/text`, session = code du tenant | `backend/app/Notifications/Infrastructure/CanalWhatsApp.php` |
| Provisioning | `POST /api/v1/tenants` sous une clé unique partagée `SAAS_API_KEY` | `backend/config/services.php:14` |
| Secret dans une réponse | `temp_password` du premier administrateur renvoyé en `201` | `backend/app/Http/Controllers/Api/V1/TenantController.php:71` |
| Cloisonnement | scope global appliqué seulement si un tenant est présent ; colonne de tenant de l'utilisateur nullable → **fail-open** | trait `BelongsToAutoEcole` |
| Autorisation | permissions et rôles déclarés, aucune vérification côté API | routes d'administration |
| Abonnement | `is_active` et `date_expiration` existent ; date jamais écrite en production ; désactivation jamais appelée | modèles et routes tenant |
| Encaissement | aucun : paiements d'élèves **déclarés** à la main | contrôleurs de tranches |

## 4. Ce que le core n'a pas encore (et que les ADR-0068 à 0090 décident)

Commerce (clients, plans, abonnements, factures, usage, portail), grand livre
complet et rapprochement, versements, accès console par organisation, clés
multiples à portée, chiffrement des données personnelles, limitation de débit
par projet, console de portefeuille, catalogue de capacités, horloges de test,
listings publics et export.
