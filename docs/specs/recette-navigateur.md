# Recette de bout en bout par navigateur — 2026-10-29

Session menée dans un vrai navigateur (Chrome, pilotage DevTools) contre la pile de développement,
**en partant de la console de toute la plateforme** : on ne prépare aucune donnée à la main, on la crée
par l'interface, puis on pilote l'API avec la clé émise dans l'interface, puis on vérifie ce que voit le
client dans le portail.

Reproduire : `make e2e-seed` crée un opérateur (identifiants affichés) ; se connecter sur
`/admin/login`, enrôler le MFA (le secret TOTP est dans la page), puis suivre les scénarios.
Le simulateur de paiement doit être actif (`PAYMENT_SIMULATOR_ENABLED=true`, dev seulement).
Ne **pas** lancer `make ci` pendant une session : il touche la base de développement et ferme les sessions.

## Scénarios et résultats

| # | Scénario | Résultat |
|---|---|---|
| 1 | Connexion console : mot de passe, enrôlement MFA (TOTP), tableau de bord | ✔ |
| 2 | Reconnexion d'un compte déjà enrôlé : mot de passe + code TOTP | ✔ |
| 3 | Balayage de tous les écrans globaux de la barre latérale (12) et de tous les écrans d'un projet (24) | ✔ aucune erreur après corrections |
| 4 | Créer une organisation puis un projet par la console | ✔ |
| 5 | Émettre une clé d'API (« Serveur complet ») : secret montré une seule fois | ✔ |
| 6 | API avec cette clé : `whoami`, paiement `payin`, **rejeu de la même clé = même paiement**, simulation, lecture | ✔ |
| 7 | Argent : 50 000 en séquestre, transfert à deux jambes (45 000 / 5 000), retrait enregistré (20 000), **découvert refusé `409 insufficient-funds`**, paiement sans clé d'idempotence refusé `400` | ✔ |
| 8 | La console montre le paiement, les portefeuilles (total 30 000 = 50 000 − 20 000), les écritures de chaque portefeuille | ✔ |
| 9 | **Grand livre** du projet : balance par devise, somme nulle, écritures | ✔ (écran créé pendant la session) |
| 10 | Enregistrement d'un endpoint webhook : URL locale refusée (garde anti-SSRF) | ✔ refus attendu ; la livraison signée est prouvée par les tests automatisés |
| 11 | Créer une offre, lui ajouter une version, la publier (console) | ✔ après correction (défauts 1 et 2) |
| 12 | Créer un client, l'abonner (essai 14 jours), droits et quotas visibles sur sa fiche | ✔ |
| 13 | Facture manuelle (2 lignes), émission, numéro `FACT-2026-000001`, **encaissement hors ligne**, validation, comptabilisation | ✔ après correction (défaut 3) |
| 14 | Réglages du portail par la console (nom, couleur, adresse de retour, autorisations) | ✔ |
| 15 | Portail client : lien à usage unique, accueil à la marque réglée dans la console, facture « Payée » | ✔ |
| 16 | Portail : payer une facture par MTN Mobile Money (simulateur) → confirmation → **facture payée 3 s plus tard** (réveil du relais après commit) | ✔ |
| 17 | Quota : 10 unités admises, **la 11ᵉ refusée `429`**, un rejeu compté une fois ; le portail affiche « Quota atteint » | ✔ |
| 18 | Portail : « Ne pas renouveler » puis « Reprendre le renouvellement » | ✔ |
| 19 | Portail : page des offres (tarification avec avantages) | ✔ |
| 20 | **Connexion directe par code** : activation refusée sans session WhatsApp, page de connexion, mauvais code refusé, bon code accepté, arrivée sur l'espace client en mobile | ✔ |
| 21 | Indicateurs du projet (chiffres, courbes, créances, checklist) et fil d'Ariane sur chaque page | ✔ après corrections (défauts 4 et 6) |

## Défauts trouvés par la session, corrigés avec un test

| # | Défaut | Correction |
|---|---|---|
| 1 | « Nouveau plan » : erreur 500 (route générée avec un code de plan vide) | route `plans/versions` qui lit le code dans le formulaire (`BillingPlansPageTest`) |
| 2 | Tout formulaire de la console envoie des **chaînes** à des commandes typées `int` en mode strict : création d'une version, reçu hors ligne, facture, jours offerts, réglages du commerce… plantaient dans un vrai navigateur (les tests envoyaient des entiers) | casts explicites ; les tests envoient des chaînes |
| 3 | Facture manuelle impossible avec moins de 3 lignes (champs `required` sur les lignes vides) | seule la première ligne est obligatoire, les lignes vides sont ignorées ; libellé « Taxe (‰) » corrigé en points de base |
| 4 | Les tuiles « Encaissé », « Créances », « Attrition » affichaient « 85 » pour « 85 000 XAF » (le composant convertissait en nombre) | propriété `text` du composant, test dédié |
| 5 | Le grand livre d'un projet n'avait aucun écran | `/admin/projects/{id}/ledger` (balance par devise, contrôle somme nulle, transactions par curseur) |
| 6 | Pas de fil d'Ariane dans la console | `AdminBreadcrumbComposer` : dérivé de la route, nom du projet lu par un port à portée requise |
| 7 | Colonne « Tiers » des portefeuilles : identifiant interne tronqué | référence du tiers (`escrow`, `partner:7`) via `PartyLookup::externalRefsOf` |
| 8 | Montants console non séparés (« 50000 ») | `Money::groupedAmount()` sur tous les écrans de la console |
| 9 | Message « seconde validation » faux (ADR-0095 : un seul compte valide) | « il attend sa validation » |

## Constats non corrigés (à décider)

* Les clés « Serveur complet » portent `payouts:*` et `exports:write` alors que ces fonctions n'existent pas
  (modules reportés) : à retirer de la liste tant que rien ne les consomme.
* La livraison d'un webhook et l'envoi réel d'un message WhatsApp ne sont pas testables dans cet environnement
  (pas d'adresse publique, pas de WAHA) : couverts par les tests automatisés et la recette du kit.
* Playwright n'est pas installé : la session est pilotée à la main et n'est pas rejouable en CI.
