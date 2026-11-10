# ADR-0116 — L'invitation d'un opérateur console part par e-mail

- **Statut** : Accepté
- **Date** : 2026-11-01
- **Décideurs** : commanditaire (il a attendu l'e-mail d'invitation et ne l'a pas reçu), comité d'architecture
- **Dérives concernées** : D3 utilisabilité · D5 contrat
- **Amende** : ADR-0050 (la gestion self-service des opérateurs), ADR-0069 §4 (l'invitation à jeton d'activation)

## Contexte

Un opérateur de la console s'invite en donnant son adresse ; l'invité reçoit un
**jeton d'activation** à usage unique, choisit son mot de passe et enrolle son MFA.

Ce jeton était montré **à l'opérateur**, sur la page, une seule fois (ADR-0081 S-07).
L'opérateur devait donc le transmettre lui-même — par message, par téléphone, par
la main. C'est une étape que personne n'accomplit volontiers : le commanditaire a
attendu l'e-mail d'invitation, et il n'est jamais parti.

La raison n'est pas un oubli : **le Core n'envoie aucun e-mail**. Le module
`notification` n'a qu'un adaptateur (WhatsApp/WAHA) ; le portail envoie son code de
connexion par WhatsApp (ADR-0100) ; rien n'utilise le mailer de Laravel. Le SMTP
était configuré, mais personne n'en servait.

Une organisation qui invite ses opérateurs par e-mail — le geste normal — n'était
donc pas possible.

## Décision

**Décision 1 — l'invitation part par e-mail, avec le jeton en clair.**

La console envoie l'invitation à l'adresse invitée. La lettre porte le jeton
d'activation en clair, la date d'expiration et l'adresse de la page d'activation.

Le jeton est en clair dans **une** lettre, à la personne concernée : c'est le seul
endroit où il doit se trouver, et il ne sert qu'une fois, pendant 72 heures. Le Core
ne le stocke nulle part (ADR-0081 S-07 : le secret n'est utilisable qu'une fois, à sa
création) et ne le journalise pas.

**Décision 2 — le jeton n'est jamais porté par l'adresse.**

La lettre contient le jeton **en texte**, et un lien vers la page d'activation — sans
jeton. Une URL se retrouve dans les journaux, les historiques de navigateur et les
en-têtes `Referer` ; un corps de message, non. C'est la même raison qui fait que la
page `/admin/invitation` demande de **coller** le jeton (et non de le lire dans
l'adresse) : ADR-0081 S-07 reste tenu.

**Décision 3 — le mot de passe n'est jamais choisi par le système, et jamais
transmis.**

L'invité choisit son mot de passe sur la page d'activation, et y enrolle son MFA. La
lettre ne contient ni mot de passe, ni demande de mot de passe : elle ne porte que le
jeton, qui ne vaut rien sans le mot de passe que l'invité choisira.

**Décision 4 — l'e-mail est un envoi, pas une condition.**

Si le relais SMTP est absent, éteint ou refuse l'adresse, **rien ne se passe** :
l'invitation existe déjà (elle a été créée avant l'envoi), le jeton est toujours
affiché sur la page, et la console le dit : « L'invitation n'a pas pu partir par
e-mail : transmettez le jeton ci-dessus vous-même. »

La console ne dépend donc jamais d'un relais SMTP pour inviter quelqu'un — un
courrier qui ne part pas ne doit pas empêcher une invitation d'exister.

**Décision 5 — la lettre est une vue, pas une chaîne HTML.**

La lettre est un gabarit Blade (`resources/views/mail/operator-invitation.blade.php`),
avec des styles en ligne : un client de messagerie ne charge pas de feuille de style
externe, et la CSP de la console ne s'applique pas à un message. C'est la première
lettre du Core ; elle pose le modèle : une vue, un `Mailable`, et le contenu en
texte que le message affiche.

## Alternatives écartées

**Ne rien changer : l'opérateur transmet le jeton à la main.** — Rejeté : c'est la
situation que le commanditaire a rencontrée. Elle marche, mais elle repose sur une
contrainte humaine que rien n'impose.

**Mettre le jeton dans le lien d'activation.** — Rejeté : le lien se retrouve dans
les journaux du serveur de messagerie, dans l'historique du navigateur et dans les
`Referer` des pages visitées ensuite. ADR-0081 S-07 est clair : un secret ne se
promène pas dans une adresse.

**Envoyer un mot de passe choisi par le système.** — Rejeté : ADR-0081 S-07 l'interdit
explicitement, et pour cause — un mot de passe choisi par le système est un mot de
passe qu'il faut transmettre, puis changer.

**Faire porter l'e-mail par le module `notification`.** — Rejeté : `notification` ne
connaît que WhatsApp ; lui ajouter un canal e-mail est une capacité (un adaptateur
`provider.gateway`, une configuration de prestataire, son propre catalogue
d'événements). L'invitation d'un opérateur n'est pas une notification de produit :
c'est un acte d'administration de la console, et il se passe dans la coquille
applicative, qui a le droit d'utiliser le mailer.

**Refuser l'invitation quand l'e-mail ne part pas.** — Rejeté : la décision 4.

## Conséquences

**Ce qui est gagné.** — Inviter un opérateur est un geste complet : on donne une
adresse, la personne reçoit son jeton, elle active son compte et choisit son mot de
passe. Le « transmettez-lui le jeton » disparaît de la procédure.

**Ce qui est engagé.**

- Le Core envoie maintenant des e-mails : une première lettre, donc un premier
  gabarit (`resources/views/mail/`), et une dépendance de fait au relais SMTP de
  l'installation. Elle n'est **pas** obligatoire (décision 4) : sans relais, la
  console fonctionne, elle invite en copiant le jeton.
- `APP_URL` doit désigner l'adresse **vue par l'invité** : c'est elle qui est
  inscrite dans la lettre. En local, le port publié (`8010`), pas le port interne
  du conteneur (`8000`).
- La lettre est un gabarit Blade : elle échoue comme une vue s'il est mal écrit.
  Le garde-fou des vues compilées (`view:clear` puis compilation) doit donc passer
  avant de livrer — une apostrophe échappée dans un attribut produit une vue qui ne
  compile pas, et le cache compilé la masque jusqu'au prochain nettoyage.

**Point de réévaluation.** — Si le Core devait un jour envoyer des e-mails à ses
**clients** (facture, reçu, code du portail par e-mail et non par WhatsApp), la
question du canal se reposerait : un adaptateur `notification` pour l'e-mail, avec
son propre jeu d'événements, ses quotas et sa traçabilité. Cette décision ne le
prédit pas : elle ne parle que de l'administration de la console.
