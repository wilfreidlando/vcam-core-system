# ADR-0081 — Modèle de menace et exigences de sécurité transverses

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D2 couplage · D3 données/PII · D5 contrat · D6 intégrité financière

## Contexte

La plateforme expose désormais cinq surfaces : l'API publique des projets, les
callbacks entrants des prestataires, les webhooks sortants, la console
(personnel et organisations tierces) et le portail client. Elle manipule de
l'argent et des données de clients de plusieurs organisations.

Le core a déjà des défenses solides : clés hachées, HMAC des webhooks, garde
SSRF (ADR-0058), idempotence (G7), MFA des opérateurs, anti-rejeu TOTP, audit,
statuts canoniques, triggers d'immuabilité du grand livre. Ce qui manque est
un **modèle de menace écrit**, des exigences numérotées et des contrôles
vérifiés en CI pour les nouvelles surfaces.

## Décision

### 1. Actifs et attaquants

| Actif | Pourquoi il compte |
|---|---|
| Argent (grand livre, paiements, versements) | perte directe, fraude |
| Données des clients des projets | confidentialité, confiance |
| Clés d'API, secrets de webhook, identifiants prestataires | prise de contrôle d'un projet |
| Comptes console | prise de contrôle d'une organisation ou de la plateforme |
| Disponibilité | un projet abusif ne doit pas dégrader les autres |

| Attaquant | Capacité supposée |
|---|---|
| Internet anonyme | appels non authentifiés, énumération, force brute, injection |
| Client d'un projet | session portail valide ; tente d'accéder aux données d'un autre client ou de ne pas payer |
| Projet malveillant ou compromis | clé d'API valide ; tente de lire ou d'agir sur un autre projet, ou de saturer la plateforme |
| Membre d'organisation | compte console valide ; tente d'élever ses droits ou d'agir hors de son organisation |
| Faux prestataire | forge des callbacks de paiement |
| Initié | personnel de plateforme ; détournement par saisie manuelle |

### 2. Exigences (identifiants stables, citées par les autres ADR)

| ID | Exigence | Contrôle vérifiable |
|---|---|---|
| **S-01** | Isolation des locataires : toute donnée est filtrée par `project_id` résolu côté serveur (clé, session, `AccessScope`), jamais par un identifiant fourni par le client seul | tests IDOR générés pour toute route à identifiant (**G15**) |
| **S-02** | Isolation financière : aucun mouvement entre projets | trigger inter-projets du grand livre (**G13**, ADR-0076 §5) |
| **S-03** | Moindre privilège des clés : portées choisies à l'émission | ADR-0082 ; test : clé sans portée → `403` |
| **S-04** | Ressource étrangère = `404`, jamais `403` | tests IDOR (G15) |
| **S-05** | Authentification forte de la console : MFA obligatoire, sessions courtes, révocables | ADR-0044/0046/0069 |
| **S-06** | Callbacks prestataires authentifiés (IP + signature), idempotents, monotones, **montant vérifié** | ADR-0051, ADR-0077 |
| **S-07** | Aucun secret utilisable dans une réponse d'API, sauf **une fois** à sa création (clé, secret de webhook, secret de callback) ; aucun mot de passe choisi par le système ; comptes créés par jeton d'activation | fitness function sur les ressources d'API avec liste blanche des champs « affichés une fois » (**G14**) |
| **S-08** | Secrets au repos : hachés s'ils se vérifient, chiffrés s'ils se relisent, jamais en clair ; clés de chiffrement versionnées et rotables | ADR-0082, ADR-0083 |
| **S-09** | Données personnelles chiffrées au repos, index aveugles pour la recherche, masquées par défaut à l'affichage | ADR-0083 |
| **S-10** | Pas de redirection ouverte : toute URL de retour vient d'une liste blanche du projet | ADR-0075 |
| **S-11** | Protection navigateur (console, portail) : CSP stricte, `frame-ancestors 'none'`, HSTS, cookies `__Host-` `Secure` `HttpOnly` `SameSite`, CSRF synchronisé | test des en-têtes en CI |
| **S-12** | Limites de débit par projet, par clé, par IP ; limites dédiées pour la connexion, les codes et les envois de messages | ADR-0085 |
| **S-13** | Sorties HTTP uniquement par `Core\Support\Integration`, garde SSRF sur les URL fournies par un projet | G6c, ADR-0058 |
| **S-14** | Entrées bornées : taille du corps, profondeur JSON, longueur des champs, pagination maximale, formats de référence | validation de requête ; tests de limites |
| **S-15** | Quatre yeux sur les opérations financières manuelles au-delà d'un seuil (encaissement hors ligne, correction, résolution d'exception, versement) | contraintes en base `approved_by <> auteur` |
| **S-16** | Journal d'audit infalsifiable par l'application : `UPDATE`/`DELETE` refusés par trigger ; chaînage par empreinte quotidienne | trigger + job de vérification de la chaîne |
| **S-17** | Journaux techniques expurgés : aucun secret, numéro de téléphone, email ou contenu de message en clair | `PayloadRedactor` étendu ; test de rédaction |
| **S-18** | Chaîne logicielle : `composer audit` et détection de secrets (gitleaks) bloquants en CI ; dépendances épinglées | CI |
| **S-19** | Tests dynamiques : analyse de base OWASP ZAP sur le staging à chaque déploiement ; test d'intrusion externe avant l'ouverture à des organisations tierces | pipeline de déploiement |
| **S-20** | Événements de sécurité alertés : échecs MFA répétés, callbacks rejetés, violations d'invariants, refus IDOR inhabituels, création de clé, changement de rôle | règles d'alerte (ADR-0085) |

### 3. Menaces par surface (STRIDE résumé)

| Surface | Menaces principales | Exigences |
|---|---|---|
| API publique | usurpation de clé, accès inter-projets, rejeu, saturation | S-01, S-03, S-04, S-12, S-14, G7 |
| Callbacks prestataires | forge, rejeu, altération du montant | S-06, S-17 |
| Webhooks sortants | SSRF, fuite de secret, rejeu côté destinataire | S-13, signature horodatée (ADR-0009), ADR-0082 |
| Console | vol de session, élévation, IDOR inter-organisations, fraude interne | S-05, S-11, S-15, S-16, ADR-0069 |
| Portail | IDOR entre clients, redirection ouverte, force brute du code, XSS via personnalisation | S-01, S-10, S-11, S-12, ADR-0075 |
| Base et sauvegardes | lecture de données personnelles ou de secrets | S-08, S-09, sauvegardes chiffrées |
| Planificateur et workers | double exécution, traitement hors projet | idempotence, `onOneServer`, contexte projet explicite dans chaque job |

### 4. Processus

- Toute ADR qui ajoute une surface ou un flux d'argent contient une section
  **Sécurité** qui cite les exigences S-xx concernées.
- Une revue de sécurité (skill `security-review`) est exigée avant la fusion
  de tout lot qui touche l'authentification, l'argent ou une nouvelle surface.
- Les vulnérabilités sont classées (critique 48 h, haute 7 j, moyenne 30 j) et
  suivies jusqu'à correction.

## Alternatives écartées

- **Sécurité au cas par cas, dans chaque ADR, sans référentiel.** Rejeté : les
  mêmes exigences seraient formulées différemment et testées inégalement.
- **Tout confier à un pare-feu applicatif.** Rejeté : utile en complément ;
  l'isolation et l'intégrité financière se garantissent dans le code et la base.

## Conséquences

- Trois nouveaux garde-fous à créer avec les lots concernés : **G13**
  (isolation financière, trigger), **G14** (aucun secret dans une réponse hors
  création), **G15** (tests IDOR générés). Ils entrent dans
  `GuardRegistry.php` et `garde-fous-inactifs.md` au moment de leur
  implémentation.
- Le journal d'audit gagne un trigger d'immuabilité (S-16) ; la rédaction de
  données personnelles n'y est pas nécessaire car il ne stocke pas de valeurs
  personnelles (ADR-0083 §4).

## Vérification

- Chaque exigence S-xx a au moins un test automatisé ou un contrôle de
  pipeline nommé dans la colonne « contrôle ».
- `make guard-test` injecte une violation de G13, G14 et G15 et vérifie le
  rejet, comme pour les garde-fous existants.
