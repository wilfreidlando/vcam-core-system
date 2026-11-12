# Les secrets du Core — ce qu'il faut poser, dans quel ordre, et ce que casse un changement

La [fiche de déploiement](../DEPLOIEMENT.md) §3 donne **le nom** et le rôle des
variables d'environnement. Elle ne donne pas **l'ordre**, et elle ne dit pas ce que
casse le changement d'une clé. On se retrouve donc à déployer, à découvrir qu'il
manque une clé, et à ne pas savoir laquelle — ni si on peut la regagner.

Cette page est la checklist. Elle est courte : **une clé, une ligne**, et la
question qui compte — *est-ce que je peux la changer plus tard ?*

> **La règle qui tient tout le reste** : une clé ne se partage pas, ne se copie pas
> d'un environnement à l'autre, et **ne se communique pas**. Elle ne vit que dans le
> `.env` du serveur (`600`, propriétaire `root`) et, pour ce qui est chiffré en base,
> **chiffré avec** une de ces clés.

---

## 1. Les secrets d'environnement

| Secret | Ce qu'il protège | Comment le poser | Peut-on le changer ? |
|--------|------------------|------------------|---------------------|
| `APP_KEY` | Le chiffrement de l'application **et de tous les secrets enregistrés en base** : secret d'un point de livraison, identifiants d'une application de prestataire | `php artisan key:generate --show` (affiche sans écrire) | **Jamais seul.** `APP_PREVIOUS_KEYS` permet de déchiffrer l'ancien et chiffrer le nouveau — mais l'ancien doit rester dans la liste |
| `TRUSTED_PROXY_TOKEN` | La preuve que les en-têtes transmis viennent du nginx voisin. Sans lui, l'application **refuse la requête** (32 caractères minimum) | `openssl rand -hex 32`, posé **aussi** par le sidecar nginx | Oui, mais il faut changer les deux côtés **dans la même fenêtre** |
| `DB_PASSWORD` | L'accès aux données | `openssl rand -base64 32` | Oui — c'est un mot de passe ordinaire |
| `REDIS_PASSWORD` | L'accès au cache et aux files | `openssl rand -base64 32` | Oui |
| `WAHA_API_KEY` | L'accès à l'instance WhatsApp auto-hébergée | L'instance WAHA la donne | Oui |
| `WAHA_WEBHOOK_SECRET` | La preuve qu'une notification vient bien de WAHA | `openssl rand -hex 32`, **la même valeur** des deux côtés | Oui, mais **les deux côtés ensemble**, sinon chaque envoi est refusé |
| `RESEND_API_KEY`, `MAIL_PASSWORD` | L'envoi d'e-mails | Le fournisseur | Oui |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Les sauvegardes sur S3 | La plateforme | Oui — **mais les sauvegardes déjà chiffrées avec l'ancienne clé deviennent illisibles** |

### Les données client : plus aucune clé

Le Core **ne chiffre plus les données client** (ADR-0123, 2026-11-12) : le nom,
l'e-mail et le téléphone sont conservés **en clair**, comme dans l'application
qui les déclare. Il n'y a donc **aucune clé de confidentialité à poser**, et
aucune rotation non plus — ni `PII_ENCRYPTION_KEYS`, ni `PII_BLIND_INDEX_KEY`,
ni la commande `privacy:generate-key` qui les produisait.

Ce qui n'a pas changé, c'est l'**exposition** : l'API ne rend l'e-mail et le
téléphone qu'à un appelant portant `customers:read`, la console les masque par
défaut (le démasquage est un acte audité), le journal d'audit ne porte aucune
valeur personnelle, et l'effacement d'un client vide son identité.

> Un `.env` qui contient encore `PII_ENCRYPTION_KEYS` ou `PII_BLIND_INDEX_KEY`
> peut les retirer : plus rien ne les lit.

---

## 2. Les secrets qui ne sont pas dans l'environnement

Ils existent, mais ils vivent **en base**, chiffrés par `APP_KEY`, ou ils ne sont
jamais stockés du tout. Les connaître évite de les chercher dans le `.env`.

| Secret | Où il est | Ce que l'opérateur voit |
|--------|-----------|------------------------|
| Secret d'un **point de livraison** (webhook) | En base, **chiffré** | **Une seule fois**, à la création. La rotation garde l'ancien valide pendant une fenêtre (`X-Signature-Previous`), puis `webhooks:purge-expired-secrets` l'efface |
| Identifiants d'une **application de prestataire** (paiement) | En base, **chiffrés** | À la saisie, dans la console |
| **Clé d'API** d'un projet | L'empreinte en base ; **le secret n'est stocké nulle part** | **Une seule fois**, à l'émission. Un secret perdu se révoque et se réémet |
| **Secret d'activation** d'un opérateur | Jamais stocké : **empreinte** en base | **Une seule fois**, dans l'invitation ; il expire après 72 h et ne sert qu'une fois |
| **Code de connexion** du portail client | Jamais stocké : dérivé, à durée de vie courte (10 min, 5 essais) | Envoyé au client |

---

## 3. L'ordre de mise en place

Pour une première installation, dans cet ordre — chaque étape suppose la précédente.

1. **`APP_KEY`** — parce que tout ce qui suit se chiffre avec elle.
2. **`TRUSTED_PROXY_TOKEN`** — sans elle, l'application refuse toute requête ; on
   découvre le problème au premier `curl`.
3. **`DB_PASSWORD`** et **`REDIS_PASSWORD`** — l'application ne démarre pas sans la
   base.
4. Les **fournisseurs** : `WAHA_*`, `RESEND_API_KEY`, `MAIL_*`.
5. Les **sauvegardes** : `AWS_*` — à brancher **avant** d'avoir des données à perdre.

**La vérification** : `curl -fsS https://<adresse>/health/ready` répond 200, et
`make deploy-status` ne signale rien.

---

## 4. La rotation : ce qui peut changer, et ce qui casse

| Secret | La rotation | Ce qui casse | Fenêtre |
|--------|-------------|--------------|---------|
| `APP_KEY` | `APP_PREVIOUS_KEYS` (la plus récente **d'abord**) puis la nouvelle `APP_KEY` | Rien si l'ancienne reste dans la liste ; **tout** si on l'enlève | Le temps de redéployer |
| `TRUSTED_PROXY_TOKEN` | Nouvelle valeur, **côté nginx et côté application** | Les requêtes pendant que les deux côtés divergent | Quelques minutes |
| `WAHA_WEBHOOK_SECRET` | Nouvelle valeur, **côté WAHA et côté Core** | Chaque notification WhatsApp | Quelques minutes |
| Secret d'un **point de livraison** | L'endpoint expose la rotation : l'ancien continue de signer pendant 1 à 72 h | Rien si le client accepte les deux signatures | Jusqu'à 72 h |
| **Clé d'API** | On émet une nouvelle clé et on révoque l'ancienne | L'application cliente si elle n'a pas encore changé de clé | Le temps du changement côté client |

**Règle générale** : une rotation se prépare **du côté de celui qui consomme le
secret** avant de changer le côté producteur. L'inverse, c'est la panne.

---

## 5. Les écarts connus

- **`PII_ENCRYPTION_KEYS` et `PII_BLIND_INDEX_KEY` ne figurent plus** dans les
  fichiers `.env.production.example` et `.env.staging.example` : le Core ne chiffre
  plus les données client (ADR-0123, **2026-11-12**). Les deux clés, présentes dans
  les modèles depuis le 2026-10-31, ont disparu ; un `.env` qui les contient encore
  peut les retirer — plus rien ne les lit.
- Les **identifiants de paiement** (`MTN_MOMO_*`, `ORANGE_MONEY_*`) ne sont pas des
  variables d'environnement : ce sont des **applications de prestataire** enregistrées
  en base, par projet, depuis la console. Elles se posent donc **après** le premier
  déploiement, pas avant.
- La **rotation de `APP_KEY`** n'a pas de procédure automatisée : elle est manuelle,
  et le risque est de retirer l'ancienne clé de `APP_PREVIOUS_KEYS` trop tôt.

---

## Voir aussi

- [Fiche de déploiement](../DEPLOIEMENT.md) — les variables, l'installation, le
  retour arrière.
- [Déploiement en production](deploiement-production.md) — l'installation complète, du
  serveur vierge au premier paiement.
- [Observabilité](observabilite.md) — ce qui est journalisé, et à quel niveau.
