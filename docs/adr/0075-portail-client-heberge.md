# ADR-0075 — Le portail client hébergé : sessions créées par le projet, connexion directe optionnelle

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D1 périmètre · D3 données/PII · D5 contrat
- **Nouveau module** : `portal` (graphe : `support`, `identity`, `billing`, `payment`, `notification`, `audit`)

## Contexte

Le client d'un projet SaaS a besoin d'un espace pour **voir son abonnement,
payer ses factures, télécharger ses reçus, suivre son usage et changer de
plan**. Si chaque projet le construit, la duplication revient — avec autant de
failles possibles qu'il y a de projets.

Deux questions de conception dominent :

1. **Comment le client entre-t-il ?** Le projet a déjà authentifié son
   utilisateur ; lui redemander un mot de passe crée un second annuaire.
2. **Que se passe-t-il si une clé de projet fuit ?** Une clé de projet peut
   déjà lire les clients, créer des abonnements et initier des paiements par
   l'API : le portail ne doit pas lui donner **plus** que cela.

## Décision

### 1. Entrée principale : session de portail créée par le serveur du projet

```
Serveur du projet ──POST /v1/portal/sessions──▶ core      { customer_ref, return_url, flow? }
                 ◀── { url, expires_at } ──────
Navigateur du client ──GET url──▶ portail      (jeton à usage unique, échangé contre un cookie)
Portail ──"Retour"──▶ return_url               (liste blanche du projet)
```

- Portée de clé dédiée : `portal:write` (ADR-0082). Une clé sans cette portée
  ne peut pas ouvrir de session.
- Le jeton : 32 octets aléatoires, **seul son HMAC est stocké**, valable
  **10 minutes**, **usage unique** (consommation `UPDATE … WHERE consumed_at IS NULL
  AND expires_at > now()`).
- À l'échange, le portail pose un cookie de session `__Host-portal`
  (`Secure`, `HttpOnly`, `SameSite=Lax`, chemin `/`), **rotation** de
  l'identifiant de session, durée d'inactivité 30 min, durée absolue 8 h.
- `flow` optionnel : `manage` (défaut), `pay_invoice` (`invoice_id`),
  `change_plan` — le portail ouvre directement l'écran voulu.
- **Rayon d'explosion** : une clé `portal:write` compromise permet d'ouvrir le
  portail d'un client du projet — ce que l'API du projet permet déjà de lire et
  faire. Aucune élévation. Chaque création de session est auditée
  (projet, client, adresse IP du serveur appelant).

### 2. Entrée secondaire, désactivée par défaut : connexion directe par code

Pour les clients qui arrivent sans passer par l'application (lien dans un
courriel de facture, par exemple) :

- le client saisit son email ou son téléphone sur la page de connexion du
  portail du projet ;
- le core envoie un **code à 6 chiffres** par le canal configuré du projet
  (module `notification`, session de canal du projet) ;
- code stocké sous forme de HMAC, valable 10 min, **5 essais**, renvoi au plus
  toutes les 60 s ;
- réponse **identique** que le contact existe ou non (pas d'énumération) ;
- limites de débit par identifiant, par IP et par projet (ADR-0085), et
  plafond d'envois par projet et par heure (protection contre le pompage de
  SMS/messages).

Activée par projet (`portal_settings.direct_login_enabled`). Elle n'ouvre
**que** le portail, jamais l'application.

### 3. Ce que le client peut faire

| Écran | Actions |
|---|---|
| Accueil | état des abonnements, prochaine échéance, montant dû |
| Abonnements | voir ; annuler à échéance (si autorisé par le projet) ; changer de plan parmi les plans `public` (si autorisé) |
| Factures | lister, voir, télécharger, **payer** (choix du canal parmi ceux du projet, numéro du payeur) |
| Paiements et reçus | historique, référence de transaction rapprochable, reçus |
| Usage | compteurs de la fenêtre courante, seuils |
| Profil | mettre à jour email/téléphone (avec vérification par code du nouveau contact) |

Chaque action est une permission explicite du portail ; une route qui n'est
pas dans cette liste n'existe pas. Le projet choisit ce qui est autorisé dans
`portal_settings` (annulation libre-service, changement de plan, plans
proposés).

### 4. Personnalisation par projet

`portal_settings` : nom affiché, logo, couleur principale, URL de support,
langues, `allowed_return_urls` (liste d'origines **exactes** + préfixes de
chemin), canaux de paiement proposés. Domaine : `portal.<domaine-plateforme>/p/<slug-projet>` ;
domaine personnalisé du projet = point d'extension (ADR-0090).

### 5. Paiement depuis le portail

La route `POST /portal/invoices/{id}/pay` résout le projet **depuis la
facture** et vérifie que la facture appartient au client de la session
(`404` sinon). Elle appelle le même use case que `POST /v1/invoices/{id}/pay`
(ADR-0073 §2), avec une clé d'idempotence liée au formulaire (jeton de
formulaire à usage unique) : un double clic ne crée pas deux paiements.

## Sécurité

| Menace | Contrôle |
|---|---|
| Redirection ouverte | `return_url` validée contre `allowed_return_urls` (comparaison d'origine exacte + préfixe de chemin), sinon `422 return-url-not-allowed` |
| Vol du lien de session | usage unique, 10 min, échange immédiat contre un cookie ; le lien ne donne rien une fois consommé |
| Fixation de session | identifiant de session régénéré à l'échange et à chaque élévation |
| CSRF | jeton synchronisé sur toute action qui modifie un état ; `SameSite=Lax` |
| XSS | gabarits échappés par défaut ; CSP stricte (`default-src 'self'`, pas de script tiers ni `unsafe-inline`) ; valeurs de personnalisation validées (couleur `^#[0-9a-f]{6}$`, logo en `https:` servi en `img-src`) |
| Clickjacking | `frame-ancestors 'none'` |
| Isolation entre clients | toute requête est filtrée par `(project_id, customer_id)` de la session ; tests IDOR générés (G15) |
| Isolation des surfaces | le portail est servi sur un hôte distinct de la console et de l'API : aucun cookie partagé |
| Force brute du code | 5 essais, verrouillage temporaire, limites par IP/identifiant/projet |
| Pompage de messages | plafond horaire d'envois de codes par projet, alerte au dépassement |

## Alternatives écartées

- **Mot de passe pour le client.** Rejeté : second annuaire, réinitialisation,
  fuite de mots de passe réutilisés — pour un besoin servi par la session
  créée par le projet.
- **Jeton en fragment d'URL à échanger par l'application.** Rejeté : impose du
  JavaScript côté application et expose le jeton au navigateur sans bénéfice ;
  l'échange immédiat côté portail est plus simple et plus sûr.
- **Portail dans la console d'administration.** Rejeté : deux audiences, deux
  postures de sécurité ; un défaut d'autorisation exposerait l'une à l'autre.
- **Pas de portail, seulement l'API.** Rejeté : chaque projet referait le même
  espace client.

## Conséquences

- Nouveau module `portal` : tables `portal_sessions`, `portal_login_codes`,
  `portal_settings` ; gabarits ; middleware `portal.session`.
- Le portail n'a **aucune** table métier : il appelle `billing` et `payment`
  par `Contracts/`.
- Le guide d'intégration gagne la section « ouvrir le portail d'un client ».

## Vérification

- Test : lien consommé deux fois → la seconde échoue ; lien expiré → échoue.
- Test : `return_url` hors liste (y compris `https://projet.example.com.evil.io`)
  → `422`.
- Test IDOR : session du client A sur la facture du client B → `404`.
- Analyse automatisée des en-têtes de sécurité du portail en CI (CSP, HSTS,
  `frame-ancestors`).
