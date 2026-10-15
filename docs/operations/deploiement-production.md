# Déploiement en production

Guide opérationnel complet, du premier déploiement sur un VPS vierge jusqu'au
premier opérateur connecté et au premier paiement prêt à être encaissé. Pour
le *pourquoi* des choix (FrankenPHP, sidecar nginx, isolation réseau),
voir [ADR-0053](../adr/0053-deploiement-production-frankenphp-vps-nginx-proxy.md).

> **Depuis l'[ADR-0064](../adr/0064-infrastructure-vps-staging-observabilite-mutualisee.md)**,
> le Core suit le **standard d'hébergement du VPS**
> ([vcam-infra-deploy](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/README.md), cloné dans `/app/vps-platform`) :
> - un staging isolé (`core-system-staging.visibilitycam.com`), déployé
>   automatiquement ;
> - une production mise à jour **uniquement** par promotion manuelle de l'image
>   validée en staging (`make prod-promote`) ;
> - une sauvegarde chiffrée **chaque nuit sur S3** (**la règle** ; **état actuel : désactivée pour le Core**, faute de bucket, voir les
>   [écarts connus](../DEPLOIEMENT.md#11-écarts-connus-avec-le-standard-à-résorber)) ; une sauvegarde à la main avant une
>   migration risquée : `deploy.sh backup prod` ; et un retour arrière automatique ;
> - **la fiche de déploiement par environnement : [`docs/DEPLOIEMENT.md`](../DEPLOIEMENT.md)**
>   (à lire en premier ; ce document-ci détaille la première installation).
>
> Les étapes 1 à 6 ci-dessous décrivent la toute première installation. Pour les
> mises à jour, voir [§12](#12-déployer-une-mise-à-jour). Pour passer une
> production existante au standard, voir [§12 bis](#12-bis-passer-une-production-existante-au-standard).

## 0. Prérequis

- Un VPS avec Docker + le plugin Docker Compose installés.
- `nginx-proxy` + `acme-companion` déjà démarrés sur ce VPS, sur un réseau
  Docker externe nommé `nginx-proxy` (`docker network ls` doit le lister).
  C'est ce qui donne à chaque projet du VPS son certificat Let's Encrypt et
  son routage par nom d'hôte — ce dépôt ne les installe pas.
- Le nom de domaine de ce déploiement pointe déjà (DNS `A`/`AAAA`) vers l'IP
  du VPS.
- Un accès SSH au VPS avec les droits Docker.

## 1. Récupérer le dépôt

```bash
git clone <url-du-dépôt> core-system
cd core-system
```

Pour une mise à jour ultérieure, voir [§12](#12-déployer-une-mise-à-jour).

## 2. Configurer l'environnement

```bash
cp .env.production.example .env
```

Puis éditer `.env` et renseigner chaque valeur. Deux à générer, pas à
inventer :

```bash
# APP_KEY — à générer UNE SEULE FOIS, ailleurs (ex. le conteneur de dev sur
# ta machine), jamais dans le conteneur de prod qui n'a pas de .env sur
# disque à réécrire. La coller telle quelle dans APP_KEY=.
docker compose exec app php artisan key:generate --show

# DB_PASSWORD — un vrai secret.
openssl rand -base64 32
```

Points d'attention en le remplissant :

| Variable | Valeur attendue |
| --- | --- |
| `APP_KEY` | Générée une fois (ci-dessus), **stable pour toujours** — la faire tourner invalide chaque session chiffrée et chaque secret déjà chiffré en base (secrets webhook, applications provider). |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<ton-domaine>` |
| `DB_PASSWORD` | Générée ci-dessus |
| `SESSION_SECURE_COOKIE` | `true` |
| `PAYMENT_SIMULATOR_ENABLED` | `false` en production, **toujours** ; `true` seulement sur le staging, pour tester sans argent réel (ADR-0066) |
| `CORE_PUBLIC_HOST` | Le domaine, sans schéma (ex. `core-system.visibilitycam.com`). Anciennement `VIRTUAL_HOST`, renommé pour que seul le sidecar web se déclare à nginx-proxy (ADR-0064) |
| `LETSENCRYPT_EMAIL` | L'adresse qui recevra les alertes d'expiration de certificat |
| `MTN_MOMO_*` / `ORANGE_MONEY_*` | Laisser vide tant que ces adaptateurs ne sont pas construits (hors périmètre actuel — voir `CLAUDE.md`) ; MyCoolPay ne passe pas par ces variables, voir [§9](#9-enregistrer-une-application-provider-mycoolpay) |
| `WAHA_BASE_URL` / `WAHA_API_KEY` | **Obligatoires pour les notifications WhatsApp** : l'adresse de votre instance WAHA (joignable depuis le conteneur, pas `localhost`) et sa clé d'API. Sans elles, tout envoi WhatsApp répond `201` avec `status: failed` (`requires a non-empty api_key credential`). |
| `WAHA_WEBHOOK_SECRET` | Secret partagé de l'en-tête `X-Webhook-Secret` des webhooks entrants de WAHA. Il n'y a pas de session par défaut : chaque client envoie avec sa propre session (`variables.session`). |

`.env` ne doit **jamais** être commité — il ne l'est pas
(`.gitignore`/`.dockerignore` l'excluent déjà tous les deux).

## 3. Construire les images

```bash
make prod-build
```

`app`, `horizon` et `scheduler` partagent la même image FrankenPHP
(cible `production` du `Dockerfile`) ; seule la commande lancée diffère.

## 4. Démarrer la stack

```bash
make prod-up
docker compose -f compose.prod.yaml ps
```

Attendre que tous les services affichent `healthy` (`app`, `webserver`,
`postgres`, `valkey`). `horizon`/`scheduler` n'ont pas de healthcheck dédié —
vérifier leurs logs (`docker compose -f compose.prod.yaml logs horizon`) s'ils
ne semblent pas tourner.

## 5. Migrations

```bash
make prod-migrate
```

Jamais automatique au démarrage du conteneur (voir ADR-0053) — toujours cette
étape manuelle, après `up`, avant d'utiliser l'application.

## 6. Vérifier que tout répond

```bash
curl -f https://<ton-domaine>/up
```

Doit répondre avec le statut de santé Laravel. Si ça échoue : vérifier que le
DNS pointe bien vers le VPS, que `nginx-proxy`/`acme-companion` ont bien émis
le certificat (`docker logs nginx-proxy-acme-companion` côté VPS, hors de ce
dépôt), et que `docker compose -f compose.prod.yaml logs webserver` ne montre
pas d'erreur de connexion vers `app:8000`.

Optionnel, pour confirmer que la documentation API est aussi accessible :
`https://<ton-domaine>/docs/api` et `https://<ton-domaine>/docs/admin`.

## 7. Provisionner le premier opérateur (admin)

Aucun opérateur n'existe encore — impossible de se connecter à la console
tant que celui-ci n'est pas créé. C'est la seule étape qui **doit** passer
par la CLI (tout le reste peut ensuite se faire depuis la console web) :

```bash
docker compose -f compose.prod.yaml exec app php artisan identity:create-operator \
    "toi@ton-domaine.tld" \
    "Ton Nom" \
    platform_admin
```

Rôles possibles : `platform_admin`, `finance_operator`, `support_operator`,
`provider_manager`, `read_only_auditor` — voir `packages/identity/src/OperatorRole.php`
pour ce que chacun autorise. Le premier opérateur doit être `platform_admin` :
c'est le seul rôle qui peut ensuite créer/lister/révoquer d'autres
opérateurs.

La commande affiche un mot de passe en clair, **une seule fois** — le noter
immédiatement dans un gestionnaire de secrets, il ne sera plus jamais
récupérable.

### 7.1 Première connexion et activation MFA

1. Aller sur `https://<ton-domaine>/admin/login`.
2. Se connecter avec l'email et le mot de passe générés à l'étape précédente.
3. Première connexion → redirection automatique vers l'enrôlement MFA : un
   secret TOTP et une URI `otpauth://` sont affichés (QR code si ton
   application d'authentification sait scanner une URI collée, sinon saisir
   le secret manuellement — Google Authenticator, Aegis, 1Password, etc.).
4. Entrer le code à 6 chiffres généré par l'application pour confirmer.
5. Tu arrives sur le dashboard — MFA est désormais **obligatoire** à chaque
   connexion suivante (`/admin/mfa/verify`).

À partir d'ici, créer d'autres opérateurs (`finance_operator`,
`support_operator`, …) peut se faire depuis la console
(`/admin/operators/create`, réservé à `platform_admin` — ADR-0050) plutôt
qu'en CLI.

## 8. Provisionner le premier client (Organization / Project / clé API)

Depuis la console (self-service, ADR-0052) — **recommandé** : `/admin/organizations/create`
puis `/admin/projects/create`, puis générer une clé API depuis la fiche du
Project. La console n'y demande jamais de choisir des scopes : une clé de
Project est toujours émise avec `ApiKeyScopes::ALL` (décision explicite,
voir son docblock) — une clé d'intégration est faite pour utiliser le Core
en entier, pas un sous-ensemble choisi à la main.

Équivalent CLI (utile pour scripter un provisioning) — la commande CLI, elle,
accepte `--scope` à la carte (c'est un outil de bootstrap plus bas niveau) ;
pour obtenir le même résultat que la console, lister les 9 scopes de
`ApiKeyScopes::ALL` (`packages/identity/src/Contracts/ApiKeyScopes.php`) :

```bash
docker compose -f compose.prod.yaml exec app php artisan identity:create-organization "Nom du client"
# → note l'Organization id affiché

docker compose -f compose.prod.yaml exec app php artisan identity:create-project <organization-id> "Nom du projet"
# → note le Project id affiché

docker compose -f compose.prod.yaml exec app php artisan identity:issue-api-key <project-id> \
    --scope='*'
```

Un projet a une seule clé (ADR-0066) ; `--replace` la régénère. Pour tester
sans argent réel, utilisez le déploiement de staging. La clé est affichée en
clair une seule fois, comme le mot de passe opérateur.

## 9. Enregistrer une application provider (MyCoolPay)

Un paiement ne peut être traité que si le Project a sa propre application
MyCoolPay enregistrée (clé publique/privée obtenues depuis le dashboard
marchand MyCoolPay — jamais une clé partagée entre Projects, ADR-0051) :

```bash
docker compose -f compose.prod.yaml exec app php artisan payment:register-provider-application \
    <project-id> my-coolpay <clé-publique> <clé-privée>
```

Équivalent console : `/admin/projects/{id}/provider-applications/create` (réservé à
`provider-applications:write`). Ré-exécuter la même commande/le même
formulaire avec de nouvelles clés **remplace** l'application existante (upsert)
plutôt que d'en créer une deuxième.

Le webhook entrant de MyCoolPay est vérifié par IP source
(`MYCOOLPAY_CALLBACK_TRUSTED_IPS`, déjà pré-rempli avec l'IP officielle
documentée par MyCoolPay) et par signature MD5 — rien à faire de plus ici
sauf si MyCoolPay change son IP source un jour.

## 9 bis. Créer la session WhatsApp du client

Un client n'envoie de WhatsApp qu'avec une session que **vous** avez créée : console →
*Sessions WhatsApp* (fiche du projet, ou menu *Plateforme* pour une session globale partagée
par tous les projets). Saisissez un nom (`ma-boutique`) : la session est démarrée chez WAHA
et enregistrée. Liez ensuite le téléphone avec les actions à côté de la session (QR code,
capture d'écran, code d'appairage). Le client passe ce nom dans `variables.session`.
Une session déjà liée chez WAHA (par exemple `default`) doit aussi être enregistrée ici
(la « créer » est sans risque : si elle existe déjà chez WAHA, elle est simplement
enregistrée).

## 10. Enregistrer un webhook endpoint (optionnel)

Si le client veut recevoir les événements (`payment.status_changed`,
`wallet.credited`, …) sur son propre serveur, plutôt que de ne compter que
sur `callback_url` par paiement ou le polling `GET /v1/payments/{id}` :
`/admin/projects/{id}/webhook-endpoints/create` en console, ou
`POST /v1/webhook-endpoints` avec la clé API du Project.

## 11. Vérifications post-déploiement

```bash
docker compose -f compose.prod.yaml ps                          # tout "healthy"
docker compose -f compose.prod.yaml exec app php artisan horizon:status
make prod-logs                                                  # logs JSON structurés, tous services
```

Deux tâches planifiées tournent dans `scheduler`, à vérifier dans ses logs :
`events:relay` (chaque minute — seul lecteur de l'outbox, relaie vers les
webhooks) et `admin:reconcile-stuck-payments` (chaque minute, ADR-0067).

## 12. Déployer une mise à jour

Deux checkouts sur le serveur (plateforme, `README.md` § 4) : `/app/core-system/staging`
(avec `.env.staging`, copié depuis `.env.staging.example`) et `/app/core-system/prod`
(avec `.env`).

```bash
# Staging — automatique (cron, plateforme `README.md` § 5), ou à la main :
cd /app/core-system/staging && make staging-deploy            # REF=origin/ma-branche pour une autre branche

# Recette sur https://core-system-staging.visibilitycam.com
#   (simulateur de paiement activé, session WhatsApp de test — jamais les clés de production)

# Production — manuelle, la même image :
cd /app/core-system/prod && make prod-promote                 # demande de taper « oui »

# En cas de problème :
make rollback ENV=prod
make deploy-status
```

`make prod-promote` enchaîne :
1. migrations avec la nouvelle image ;
2. bascule des conteneurs ;
3. contrôle de `/health/ready` (base et Valkey) pendant 2 minutes ;
4. retour automatique à la version précédente si le contrôle échoue (sauf la toute première
   promotion d'un projet existant : retour manuel).

**Aucune sauvegarde n'est prise au déploiement** (la nocturne est le filet) : avant une migration
risquée, `/app/vps-platform/bin/deploy.sh backup prod`. Les migrations du Core sont **dans les modules**
(`packages/*/database/migrations`) : les **répéter sur une copie de la production** avant de promouvoir
([guide 6, étape 5 bis](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/guides/06-core-au-standard.md)).

Les migrations ne sont jamais annulées automatiquement. Au besoin, restaurer la dernière sauvegarde
(`/app/vps-platform/bin/restore.sh prod s3://<bucket>/<préfixe>/core-system/<fichier>`).

## 12 bis. Passer une production existante au standard

À faire une fois, en heure creuse. Coupure attendue : quelques secondes, le temps de
recréer les conteneurs. **Les noms de conteneurs, le réseau `core-system-internal`
et les volumes de données restent identiques** : la base n'est ni déplacée ni
recréée.

```bash
# 0. Filet de sécurité manuel, avant toute chose
docker exec core-system-postgres sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > /root/core-avant-standard.dump

# 1. Réseau partagé de l'observabilité (sans effet sur les autres projets)
docker network create observability || true

# 2. Checkouts : COPIER (pas déplacer) le checkout actuel en …/prod. Un conteneur peut monter un
#    dossier par son chemin (ici la configuration nginx) : déplacer le dossier ferait tomber le site
#    au prochain redémarrage du conteneur. L'ancien dossier reste la base de retour arrière.
#    Le nom de projet Docker « core-system-prod » est fixé dans compose.prod.yaml, pas par le dossier.
mkdir -p /app/core-system && cp -a <checkout actuel> /app/core-system/prod
git clone <dépôt> /app/core-system/staging

# 3. Compléter /app/core-system/prod/.env avec la section « VPS standard » de
#    .env.production.example — en particulier TRUSTED_PROXY_TOKEN
#    (openssl rand -hex 32), BACKUP_PASSPHRASE et les variables S3. Remplacer VIRTUAL_HOST par
#    CORE_PUBLIC_HOST (même valeur) DANS LA COPIE seulement.
#    Créer /app/core-system/staging/.env.staging depuis .env.staging.example
#    (APP_KEY, DB_PASSWORD, TRUSTED_PROXY_TOKEN et BACKUP_PASSPHRASE DIFFÉRENTS).

# 4. Premier staging (construit l'image), puis vérification ; répéter les migrations sur une copie de la production
cd /app/core-system/staging && make staging-deploy

# 5. Promotion : migrations, bascule, contrôle de santé (une sauvegarde à la main avant : deploy.sh backup prod)
cd /app/core-system/prod && make prod-promote

# 6. Contrôles
make deploy-status
/app/vps-platform/bin/vps-audit.sh | sed -n '/core-system/,/^$/p'
```

Si l'ancienne stack d'observabilité tournait (`compose.observability.yaml`, projet
`core-system-prod`), l'étape 5 en supprime les conteneurs (orphelins du projet). Ses
volumes `core-system-prod_*-data` restent : les supprimer après vérification.
Installer ensuite l'observabilité mutualisée ([`observability/README.md`](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/observability/README.md) de la plateforme).

## 13. Opérations courantes

```bash
# Redémarrer un seul service (ex. après un souci Horizon)
docker compose -f compose.prod.yaml restart horizon

# Ouvrir un shell dans l'app
make prod-shell

# Revenir en arrière sur les migrations (chaque migration a un down())
docker compose -f compose.prod.yaml exec app php artisan migrate:rollback

# Sauvegarder : l'agent le fait chaque nuit sur S3 ; à la demande :
/app/vps-platform/bin/deploy.sh backup prod

# Restaurer (la base est REMPLACÉE par l'état exact de la sauvegarde ; jamais un simple « psql < fichier »,
# qui laisse ce qui a été créé après) :
/app/vps-platform/bin/restore.sh prod s3://<bucket>/<préfixe>/core-system/<fichier>
```

## 14. Check-list de sécurité avant de passer un Project en `live`

- [ ] `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `IDENTITY_DISABLE_MFA`
      et `IDENTITY_DISABLE_TOTP_IN_LOCAL` absents ou `false`.
- [ ] `APP_KEY` généré une fois, sauvegardé dans un gestionnaire de secrets
      (pas seulement dans `.env` sur le VPS).
- [ ] Le mot de passe du premier opérateur et sa clé API sandbox ont été
      notés au provisioning puis effacés de tout historique de terminal.
- [ ] `postgres`/`valkey` ne sont accessibles que sur le réseau Docker
      interne (`core-system-internal`) — jamais exposés sur `nginx-proxy` ni
      sur l'hôte (confirmé par construction dans `compose.prod.yaml`, à ne
      pas modifier sans relire ADR-0053 §isolation réseau).
- [ ] `MYCOOLPAY_CALLBACK_TRUSTED_IPS` reflète toujours l'IP officielle
      documentée par MyCoolPay.
- [ ] Sauvegarde : le service `backup` envoie chaque nuit une copie chiffrée sur S3 (`BACKUP_DISABLED` absent en production,
      `BACKUP_S3_*` renseignés). **État actuel du Core : désactivée** (voir les [écarts connus](../DEPLOIEMENT.md#11-écarts-connus-avec-le-standard-à-résorber)).
