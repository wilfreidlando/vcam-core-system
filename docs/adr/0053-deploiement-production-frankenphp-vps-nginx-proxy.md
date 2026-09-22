# ADR-0053 — Déploiement de production : FrankenPHP, `compose.prod.yaml` séparé, sidecar nginx pour le VPS partagé

- **Statut** : Accepté
- **Date** : 2026-09-22
- **Décideurs** : décision de l'utilisateur (choix explicite de FrankenPHP), à
  la suite de la demande de « config pour un déploiement robuste »
- **Dérives concernées** : aucune (infrastructure de déploiement, pas de
  nouvelle capacité métier)

## Contexte

`compose.yaml`/`Dockerfile` (ADR-0019, Docker-first) couvrent le développement
local. Le stage `production` du Dockerfile existait déjà mais lançait
`php artisan serve` — le serveur de développement intégré de Laravel,
explicitement documenté comme inadapté à la production (mono-thread, aucune
tolérance de charge). Ce n'était donc pas une vraie image de production, juste
un stage nommé comme tel.

L'utilisateur déploie sur un VPS qui héberge plusieurs projets derrière un
reverse proxy nginx partagé (`nginx-proxy` + `acme-companion`, réseau Docker
externe nommé `nginx-proxy`) : chaque projet joint ce réseau externe et se
signale via les variables d'environnement `VIRTUAL_HOST`/`LETSENCRYPT_HOST`/
`LETSENCRYPT_EMAIL` sur un conteneur nginx dédié au projet (`nginx:alpine`,
avec son propre `docker/nginx/prod/*.conf`) — pas un nginx unique mutualisé
édité à la main pour chaque projet.

## Décision

### Serveur web — FrankenPHP, mode classique

FrankenPHP (préférence explicite de l'utilisateur) remplace `php artisan
serve` dans le stage `production`. Binaire unique avec un cœur Caddy, pas de
paire PHP-FPM + nginx séparée à maintenir, HTTP/2 natif.

**Mode classique (une requête = un process), pas le mode worker.** Le mode
worker (façon Octane) est nettement plus rapide (processus PHP persistants)
mais exige d'auditer tout état statique/singleton du code pour éviter qu'il
fuite d'une requête à l'autre — coût réel, pas pris en même temps que le tout
premier déploiement de production d'un cœur financier. Le mode classique
préserve exactement le cycle de vie requête que le reste du Core suppose déjà
partout. Passer en mode worker reste une option future, isolée, avec son
propre audit.

Le nouveau stage `production` du Dockerfile part directement de l'image
`dunglas/frankenphp` (pas de `FROM base` — c'est un build PHP différent),
réinstalle la même liste d'extensions que `base` via `install-php-extensions`
(même outil que le reste du Dockerfile), copie `vendor/` depuis le stage
`vendor` existant (inchangé) et lance
`frankenphp run --config /etc/frankenphp/Caddyfile`.

Le `Caddyfile` (`docker/frankenphp/Caddyfile`) désactive `auto_https` et
`admin` : le TLS est terminé en amont (voir plus bas), FrankenPHP ne sert que
du HTTP en clair sur `:8000`, jamais atteint directement depuis l'extérieur.

### `compose.prod.yaml` séparé de `compose.yaml`, pas un override

Les deux fichiers partagent trop peu (FrankenPHP vs `serve`, aucun bind mount
de l'arbre de travail en prod, aucun port Postgres/Valkey publié, le sidecar
nginx) pour qu'une couche d'override reste plus lisible que deux fichiers
plats. `compose.prod.yaml` porte son propre `name: core-system-prod` pour ne
jamais entrer en collision avec une stack de dev sur le même hôte, et chaque
service a un `container_name: core-system-<service>` explicite (demande
utilisateur) plutôt que le nommage auto-généré de Compose.

### Sidecar nginx par projet, pas FrankenPHP directement sur `nginx-proxy`

`nginx-proxy`/`acme-companion` savent découvrir n'importe quel conteneur HTTP
portant `VIRTUAL_HOST`, pas seulement du nginx — brancher ces variables
directement sur le conteneur `app` (FrankenPHP) aurait marché et évité un
conteneur. Décision : garder le sidecar `nginx:alpine` dédié
(`docker/nginx/prod/app.conf`, simple `proxy_pass` vers `app:8000`, pas de
service de fichiers statiques ni de FastCGI — FrankenPHP fait déjà les deux)
pour rester cohérent avec la convention déjà en place sur les autres projets
du même VPS (`monpermis_backend_webserver` et son équivalent) — même modèle
mental, mêmes réflexes de debug/logs d'un projet à l'autre.

### Isolation réseau — Postgres/Valkey jamais sur le réseau partagé

`compose.prod.yaml` définit un réseau `default` **local au projet**
(`core-system-internal`) pour `app`/`horizon`/`scheduler`/`postgres`/`valkey`,
et attache **seulement** `webserver` à la fois à ce réseau interne et au
réseau externe `nginx-proxy`. Diverge délibérément de l'exemple fourni par
l'utilisateur (où `networks: default: name: nginx-proxy` s'applique à tout le
fichier) : mettre Postgres/Valkey sur le réseau partagé par tous les projets
du VPS voudrait dire qu'un conteneur compromis dans un projet sans rapport
pourrait atteindre directement la base de données de ce Core, en contournant
toute l'authentification applicative — inacceptable pour une plateforme
financière. Coût : un conteneur de plus dans le chemin réseau (le sidecar
nginx) ; aucune fonctionnalité perdue.

### `trustProxies` — la chaîne de deux proxys est fiable par construction

`app` n'est jamais atteignable autrement que via `nginx-proxy` → sidecar
nginx → FrankenPHP, tous sur des réseaux Docker internes. `bootstrap/app.php`
appelle donc `$middleware->trustProxies(at: '*')` : sans ça,
`$request->ip()` (le throttle par IP d'`operator-auth`, ADR-0046) et le schéma
détecté (cookies "secure", URLs de redirection) auraient reflété l'IP/le
protocole du dernier hop Docker, pas le client réel.

### Secrets et `APP_KEY` — jamais de fichier `.env` dans l'image

`.dockerignore` exclut déjà `.env`/`.env.*` (sauf `.env.example`) de tout
build — vrai pour ce nouveau stage aussi. Chaque service de
`compose.prod.yaml` lit `.env` via `env_file:`, donc Laravel ne voit ces
valeurs que comme variables d'environnement du process, jamais comme fichier
sur disque dans le conteneur. `.env.production.example` documente chaque
valeur attendue ; `APP_KEY` doit être généré une fois
(`php artisan key:generate --show`, en local, jamais dans le conteneur qui
n'a pas de `.env` à réécrire) et rester stable pour toujours — le faire
tourner invaliderait chaque session chiffrée et chaque secret chiffré déjà en
base (secrets `webhook_endpoints`, `payment_provider_applications`).

### Migrations — étape de déploiement explicite, jamais automatique au démarrage

Aucun `CMD`/entrypoint ne lance `php artisan migrate` automatiquement : avec
plusieurs réplicas potentiels d'`app` au démarrage, ce serait une course.
`docker compose -f compose.prod.yaml exec app php artisan migrate --force`
reste une commande manuelle du script de déploiement, comme documenté en tête
de `compose.prod.yaml`.

## Alternatives écartées

- **PHP-FPM + nginx classique** : aurait aussi marché, mais deux process à
  superviser au lieu d'un binaire unique, sans bénéfice pour ce Core.
- **Mode worker FrankenPHP dès ce premier déploiement** : gain de perf réel,
  mais demande un audit d'état partagé que rien ne justifie de faire dans le
  même changement que "avoir un premier déploiement qui tourne".
- **`compose.yaml` unique avec profils dev/prod** : returné pour la même
  raison qu'un override — la divergence dev/prod est trop large pour rester
  lisible dans un seul fichier.
- **Postgres/Valkey sur le réseau `nginx-proxy` partagé**, comme dans
  l'exemple fourni par l'utilisateur pour un autre projet : écarté pour
  l'isolation réseau (voir plus haut) — un choix que l'utilisateur peut
  renverser explicitement s'il a d'autres garanties (pare-feu au niveau VPS,
  par exemple), mais pas le défaut pour un cœur financier.

## Conséquences

- `make up`/`compose.yaml` (dev) restent inchangés à l'octet près — confirmé
  explicitement par l'utilisateur pendant cette session.
- Un nouveau projet sur ce VPS suit le même schéma (sidecar nginx +
  `VIRTUAL_HOST`/`LETSENCRYPT_HOST`, réseau interne dédié) sans qu'il faille
  redécouvrir ces décisions.
- Reste hors de ce changement, noté comme suite possible : tuning des workers
  Horizon pour la capacité réelle du VPS (`config/horizon.php` n'est pas
  encore publié, la configuration par défaut du package est utilisée telle
  quelle), mode worker FrankenPHP, cache de config/route au build.
