# ADR-0056 — Notifications : idempotency au niveau domain (pas de middleware HTTP)

- **Statut** : Accepté
- **Date** : 2026-09-22
- **Décideurs** : équipe Core (déclencheur : question produit — l'`Idempotency-Key` HTTP ne couvre pas le cas « j'envoie une liste de messages » et le caller n'a aucune raison raisonnable de gérer une clé UUID par envoi)
- **Dérives concernées** : D5 contrat, D2 couplage

## Contexte

Les trois routes `POST /v1/notifications/{text,image,file}` portent le middleware `idempotency` depuis le Lot 5 (ADR-0041). L'intention : offrir la même garantie que sur les paiements (rejouer une requête après un timeout ne débite pas deux fois — ici : n'envoie pas deux fois le même message, ce qui a un coût provider et un coût d'expérience destinataire). En pratique, le middleware HTTP a trois effets sur les notifications qui sont tous défavorables au ratio valeur/coût :

1. **Boilerplate côté caller** : chaque envoi doit générer une clé UUID, la passer dans `Idempotency-Key`. Pour un système qui pousse potentiellement des centaines de notifications par minute, c'est du bruit dans le SDK sans bénéfice tangible.
2. **Incompatible avec un batch** : si demain on construit `POST /v1/notifications/batch` (un array de N notifications), une seule clé HTTP ne peut pas garantir N effets indépendants. Le middleware HTTP ne passe pas à l'échelle d'un batch.
3. **Couvre un risque déjà couvert** : la table `notifications` a une contrainte unique `(project_id, idempotency_key)` (migration `2026_09_15_000003`), et `NotificationSendCascade::send()` vérifie cette contrainte **avant** d'appeler le provider (ligne 79). Le dédoublonnage est déjà garanti au niveau domain, sans le middleware HTTP.

Côté paiement, le middleware reste justifié : un double débit a un coût financier direct, et le middleware HTTP ajoute une couche « réponse cachée pour replay » qui économise un round-trip provider. Bénéfice asymétrique, deux cas distincts.

## Décision

**Retirer le middleware `idempotency` des trois routes notification.** Remplacer le contrat HTTP par un champ body optionnel `client_reference` (string, max 255). Si le caller le fournit, il devient la clé d'idempotency domain ; s'il l'omet, le Core génère un UUID et le dédoublonnage est désactivé pour cet envoi. Le tout reste interne — le champ `idempotency_key` du `Notification` aggregate continue d'exister ; seul son **source** change (champ body, plus header HTTP).

**Conséquence sur le contrat** :
- Le header `Idempotency-Key` n'est plus listé dans la doc des trois routes notification, ni les réponses 400 / 409 associées. (Pas de breaking pour un caller qui continuerait à l'envoyer — le middleware ne s'en sert plus, mais le header est juste ignoré.)
- Le champ `client_reference` apparaît dans le schéma de chaque route notification, marqué `nullable` avec `@example` réaliste et une description qui explique le comportement (même clé = même notif, sans clé = pas de dédup).
- Le `IdempotencyHeaderExtension` Scramble (`app/OpenApi/IdempotencyHeaderExtension.php`) n'a aucune modification à subir : il ajoute le header uniquement quand `in_array('idempotency', $routeInfo->route->middleware(), true)` — sans le middleware, l'extension ne s'applique plus aux routes notification. Logique d'extension inchangée, donc zéro risque de régression sur les routes paiement.

**Sur le payment, aucun changement.** Le middleware HTTP reste la bonne réponse à un coût direct.

### Mécanisme

1. **Routes** (`packages/notification/routes/api.php`) : suppression de `->middleware('idempotency')` sur les trois `Route::post('/text|image|file', ...)`.
2. **FormRequests** (`Send{Text,Image,File}NotificationRequest.php`) : ajout d'une règle `'client_reference' => ['nullable', 'string', 'max:255']` avec PHPDoc décrivant la sémantique de dédup + `@example "client-msg-abc123"`.
3. **Contrôleurs** (`Send{Text,Image,File}NotificationController.php`) :
   - Ajout d'une injection `Core\Support\Identifier` (déjà câblé `Uuid7Identifier` par `SupportServiceProvider`, pas de SP à toucher).
   - Lecture de `$data['client_reference']` ; si non-vide, c'est la clé d'idempotency ; sinon, `$this->identifier->generate()`.
   - Le reste de la chaîne est inchangé — la commande `Send{Text,Image,File}NotificationCommand` reçoit toujours un `idempotencyKey`, c'est juste qu'il vient du body ou d'un UUID généré.
4. **Doc Scramble** : `make openapi` régénère `openapi/v1.json` et `openapi/admin.json`. Audit automatique : les trois routes notification ne portent plus `Idempotency-Key` ni 400/409 ; le champ `client_reference` apparaît dans chaque schéma de body.

### Ce qui ne change pas

- Le contrat de dédup côté base de données (`notifications.idempotency_key` UNIQUE sur `(project_id, idempotency_key)`).
- Le comportement de `NotificationSendCascade` qui intercepte la collision avant d'appeler le provider.
- Le contrat HTTP sur les routes paiement (`Idempotency-Key` reste obligatoire).
- L'`IdempotencyHeaderExtension` Scramble (sa logique de filtrage par middleware est ce qui rend le retrait propre — sans toucher au code de l'extension, les routes notification cessent d'être documentées avec le header dès qu'on retire le middleware).

## Alternatives écartées

- **Garder l'header + ajouter un batch endpoint** avec un mécanisme d'idempotency par-item (`client_reference` dans chaque item du batch). Rejeté : on garde deux mécaniques pour un seul besoin, le middleware HTTP n'apporte rien que le domain ne fait déjà, et la doc reste asymétrique entre routes avec et sans `Idempotency-Key`.
- **Auto-générer un hash de contenu comme clé d'idempotency implicite** (sans `client_reference`, dédupliquation naturelle par contenu). Rejeté : ça empêcherait le caller de ré-envoyer un message corrigé (même contenu mais avec une faute de frappe, par exemple) — l'idempotency par hash de contenu est plus stricte que ce que la plupart des callers attendent. La clé explicite du caller est plus prévisible.
- **Renommer `client_reference` en `idempotency_key`** côté body pour rester cohérent avec le domain. Rejeté : « Idempotency-Key » dans un header HTTP est une convention REST (Stripe, etc.) que les callers reconnaissent. Dans un body JSON, le même nom prête à confusion avec d'autres champs idempotency (header du payment, futur champ `idempotency_key` ailleurs). `client_reference` est plus précis sur ce que le caller fait (« voici ma référence client pour cette notification »).
- **Rendre le middleware obligatoire seulement quand `client_reference` est absent** (l'inverse du choix actuel). Rejeté : la présence du header ne dit rien sur la sémantique attendue côté caller, et la complexité conditionnelle n'apporte rien — soit on exige un header, soit on accepte un champ body, pas les deux.

## Conséquences

- **Plus facile** : un caller qui envoie 10 notifications fait 10 POST avec un payload à 4 champs (channel, to_party_ref, text, variables) ou 5 (avec `client_reference`). Pas de UUID à générer, pas de header à gérer.
- **Plus difficile** : aucun — la migration est invisible côté BDD et côté domain. Le seul effet visible est sur la doc (header manquant sur 3 routes, champ body nouveau).
- **Engage** :
  - modifier `packages/notification/routes/api.php` (3 lignes) ;
  - modifier `packages/notification/src/Presentation/Http/Requests/Send{Text,Image,File}NotificationRequest.php` (ajout d'une règle + PHPDoc) ;
  - modifier `packages/notification/src/Presentation/Http/Controllers/Send{Text,Image,File}NotificationController.php` (injection `Identifier`, lecture `client_reference`, fallback UUID) ;
  - regénérer `openapi/v1.json` et `openapi/admin.json` via `make openapi` (automatique dans `make ci` côté contribution future) ;
  - **caller-side** : tout SDK existant qui passerait `Idempotency-Key` continuera de fonctionner, le header sera juste ignoré. Pas de breaking change observable.
- **Point de réévaluation** : si un batch endpoint est construit plus tard (et qu'il devient tentant de réutiliser le middleware HTTP sur ce batch), reconsidérer cette ADR à la lumière du volume attendu — pour des batchs de <50 items, l'auto-génération côté Core reste viable ; au-delà, le caller aura probablement intérêt à fournir ses propres clés. Pas de pression à refactorer tant qu'aucun signal de friction n'apparaît.