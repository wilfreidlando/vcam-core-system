# 07 — Portail client

*Ce chapitre est la référence technique du portail client. Ce que fait un client dans son espace se lit dans le [parcours F17](../parcours/README.md).*

Le portail est l'espace que voit le client final de vos produits. C'est votre vitrine
technique : pas de JavaScript, une politique de sécurité de contenu stricte, et un rendu
qui tient sur un téléphone. Ce chapitre dit comment il s'ouvre, ce qu'il montre, et ce
qu'il ne montre pas.

---

## 1. Les deux façons d'entrer

### Par un lien à usage unique — le mode normal

C'est votre application qui ouvre l'espace. Elle appelle `POST /v1/portal/sessions` avec la
clé d'API du projet, et reçoit une URL. Elle la donne au client. Le client clique.

1. Le client suit le lien `/portal/enter/{token}`.
2. Le Core échange le jeton contre un cookie de session. Le jeton **ne marche qu'une fois**
   et **expire au bout de 10 minutes**.
3. Le client arrive sur `/portal`.

Un jeton déjà utilisé ou expiré donne la page `« Ce lien n'est plus valide »` (HTTP 410) :
`« Un lien d'accès ne fonctionne qu'une seule fois et pendant quelques minutes. »`

**Deux formes de session**, demandées à l'API par le champ `flow` :

| `flow` | Ce que le client voit à l'arrivée |
|---|---|
| `manage` (défaut) | le tableau de bord |
| `pay_invoice` | la facture à payer, directement |

Un appel peut aussi **déclarer le client** et le **souscrire à une offre** en même temps
que d'ouvrir la session — c'est le branchement « un appel » décrit dans le kit Laravel.

### Par un code à 6 chiffres — le mode optionnel

**Désactivé par défaut.** Le projet doit l'activer dans ses réglages du portail, et nommer
la session WhatsApp qui envoie le code.

Le client va sur `/portal/{projectId}/login` et saisit son numéro de téléphone.

| Étape | Ce qui se passe |
|---|---|
| 1 | Le client saisit son numéro (`+237 6XX XX XX XX`) et clique `« Recevoir mon code »`. |
| 2 | Le Core répond **toujours** la même chose : la page `« Saisissez votre code »`. **La réponse ne dit jamais si le numéro existe.** |
| 3 | Si le numéro correspond à un client du projet, un code à 6 chiffres part par WhatsApp. Sinon, **rien n'est envoyé** — et le client ne peut pas le savoir. |
| 4 | Le client saisit le code et clique `« Se connecter »`. |

**Les règles du code :**

| Règle | Valeur |
|---|---|
| Longueur | 6 chiffres |
| Validité | 10 minutes |
| Essais | 5 |
| Renvoi | au plus 1 par minute |
| Usage | unique — un nouveau code annule les précédents |

Le message envoyé est : `{nom affiché} : votre code de connexion est {code}. Il est valable
10 minutes. Ne le communiquez à personne.`

En cas d'erreur : `« Ce code est incorrect ou n'est plus valide. Vérifiez-le, ou demandez
un nouveau code. »`

**Les limites sont fermées.** Si le cache de compteurs est indisponible, le service répond
`503` (`« La connexion par code est momentanément indisponible. »`) et **rien n'est
envoyé**. Un plafond de projet atteint ne se signale pas au navigateur : un message le
révélerait.

> **Un numéro partagé par deux clients du projet est ambigu :** aucun code n'est envoyé.
> Un client effacé non plus.

---

## 2. Le tableau de bord

`GET /portal` — `« Bonjour {nom} »`.

Trois indicateurs :

| Indicateur | Ce qu'il montre |
|---|---|
| **À régler** | le montant dû, par devise. `« À jour »` si rien n'est dû. Sinon `« Échéance le {date} »` ou `« N factures en attente »`. |
| **Prochain renouvellement** | la date, ou `« — »`. `« Aucun contrat en cours »` si rien. |
| **Payé sur 12 mois** | la somme de vos règlements sur un an. |

Quatre cartes :

- **« Factures à régler »** — les factures ouvertes, avec le bouton `« Payer »`.
- **« Votre abonnement »** — l'offre, le prix, le statut, et `« Se termine le {date} »` si
  la résiliation est programmée, sinon `« Prochaine échéance : {date} »`. Un lien
  `« Gérer »`.
- **« Utilisation »** — jusqu'à trois jauges. Un lien `« Tout voir »`.
- **« Activité récente »** — les quatre dernières factures.

La navigation, à gauche (ou dans le menu sur téléphone) :

`Accueil` · `Mon abonnement` · `Offres et tarifs` · `Factures` · `Paiements` ·
`Utilisation` · `Mon profil`

En bas : `« Retour à {nom} »` (seulement si le projet a autorisé une adresse de retour),
`« Besoin d'aide ? »` (seulement si le projet a défini une URL d'aide), la référence du
client, et `« Se déconnecter »`.

---

## 3. Les factures et leur paiement

`GET /portal/invoices` — la liste. Chaque ligne : le numéro, `« Émise le {date} »` (et
l'échéance si la facture est ouverte), le montant, le statut.

**Les factures en brouillon ne sont jamais visibles.** Le client ne voit que ce qui lui
concerne : une facture d'un autre client donne `« Cette page n'existe pas ou ne vous est pas
accessible. »` (404), jamais « interdit ».

### Le détail d'une facture

`GET /portal/invoices/{invoiceId}` :

- numéro + statut ;
- le tableau `« Désignation »`, `« Qté »`, `« Montant »`, avec la période si la ligne en a
  une ;
- `« Sous-total »`, `« Taxes »` (si taxe > 0), `« Total »` ;
- `« Émise le »`, `« Échéance »`, `« Payée le »` ;
- la carte `« Paiements »` : les règlements déjà passés.

### Payer une facture

Carte `« Payer cette facture »` :

1. Choisissez un **moyen de paiement** parmi les boutons radio. Les moyens proposés sont
   ceux que le projet a autorisés **croisés** avec les canaux réellement routables par la
   plateforme : `MTN Mobile Money`, `Orange Money`, `Carte bancaire`.
2. Saisissez le `« Numéro de téléphone du compte »` (`+237 6XX XX XX XX`).
3. `« Payer {montant restant} »`.

Deux résultats :

| Ce qui s'affiche | Ce que ça veut dire |
|---|---|
| `« Confirmez sur votre téléphone »` | le prestataire demande une validation par code. `« Une demande de confirmation vient d'être envoyée sur votre téléphone. Validez-la avec votre code secret. »` |
| `« Continuez sur la page de paiement sécurisée pour confirmer. »` | un lien `« Continuer vers le paiement »` |
| `« Paiement en cours de traitement »` | `« Nous attendons la confirmation de l'opérateur. »` |

Dans tous les cas, un bouton `« Voir ma facture »` ramène à la facture.

Si le canal n'est pas proposé : `« Le paiement en ligne n'est pas disponible pour cette
facture. Contactez le support pour la régler. »`

Si le lancement est refusé : `« Le paiement n'a pas pu être lancé. Réessayez ou contactez
le support. »` Le motif technique reste dans les journaux, il n'est pas montré au client.

> **Un double-clic ne paye pas deux fois.** Le portail dérive sa clé d'idempotence d'un
> nonce de formulaire : le même clic produit le même paiement.

---

## 4. L'abonnement

`GET /portal/subscriptions` — `« Mon abonnement »`.

Par contrat : le statut, `« Tarif »`, `« Période en cours »`, `« Fin de l'essai »`,
`« Abonné depuis »`.

### Ce que le client peut faire — et seulement s'il y est autorisé

Ces actions existent dans le portail **si le projet les a activées** dans ses réglages :

| Action | Réglage qui l'active |
|---|---|
| `« Ne pas renouveler »` (résiliation à l'échéance) | `allow_self_cancel` |
| `« Reprendre le renouvellement »` | `allow_self_cancel` |
| `« Souscrire »` / `« Choisir cette offre »` / `« Renouveler mon abonnement »` | `allow_plan_change` |

Si l'action n'est pas autorisée, le client ne voit pas le bouton. Si le projet a désactivé
la fonctionnalité, le client voit `« Pour changer d'offre, contactez {nom}. »`

> **Ce n'est pas le client qui décide, c'est le projet.** Les deux réglages sont dans
> l'écran `« Portail client »` de la console, section `« Ce qu'un client peut faire
> seul »`.

Un changement d'offre **programmé** affiche : `« Un changement d'offre est programmé pour
la fin de la période en cours. »` — le nouveau tarif s'applique à la fin de la période en
cours, sans frais surprise.

Une résiliation programmée affiche : `« Ce contrat ne sera pas renouvelé : il se termine le
{date}. Vous gardez l'accès jusque-là. »`

---

## 5. Les offres

`GET /portal/plans` — `« Choisissez l'offre qui vous convient »`.

Le catalogue montre **seulement les offres publiques**, dans leur version publiée, triées
par prix croissant. Chaque offre affiche son nom, sa description, son prix et sa
périodicité, et la liste des points forts.

| Badge ou bouton | Signification |
|---|---|
| `« Votre offre »` | l'offre actuelle du client |
| `« Offre actuelle »` | pas de bouton — c'est déjà celle-là |
| `« Souscrire »` | le client n'a pas de contrat |
| `« Choisir cette offre »` | le client a un contrat et peut en changer |

Si aucune offre n'est proposée : `« Aucune offre n'est proposée pour le moment. »`

---

## 6. L'utilisation

`GET /portal/usage` — `« Utilisation »`.

Les jauges montrent, par compteur : `X utilisé`, `X / Y`, ou `X utilisés · illimité`. Les
lecteurs d'écran annoncent `« Quota presque atteint »` à partir de 80 % du seuil défini
par l'offre, et `« Quota atteint »` à 100 %.

Sous les jauges, la carte `« Fonctionnalités incluses »` : une liste à coches, `✓` pour ce
qui est inclus, `–` pour ce qui ne l'est pas.

Si l'offre n'a aucun quota : `« Votre offre n'a aucun quota à suivre. »`
Sans abonnement actif : `« Aucun abonnement actif : il n'y a pas d'utilisation à suivre. »`

---

## 7. Le profil

`GET /portal/profile` — `« Profil »`.

| Information | Ce qui est montré |
|---|---|
| `Nom` | en clair |
| `Référence` | en clair |
| `E-mail` | **masqué** : `x•••@domaine` |
| `Téléphone` | **masqué** : `+237 ••• ••12` |
| `Client depuis` | la date |

La carte `« Sécurité »` rappelle : `« Cet espace s'ouvre depuis {nom} et se ferme après 30
minutes d'inactivité. »`, avec un bouton `« Se déconnecter »`.

> **Le profil est en lecture seule.** Le client ne peut pas changer son e-mail ni son
> numéro depuis le portail.

---

## 8. Les pages d'erreur

| Situation | Page | HTTP |
|---|---|---|
| Session fermée ou expirée | `« Votre session a expiré »` | 401 |
| Formulaire expiré (token CSRF invalide) | `« Formulaire expiré »` | 419 |
| Lien d'accès invalide, expiré ou déjà utilisé | `« Ce lien n'est plus valide »` | 410 |
| Page inexistante ou ressource d'un autre client | `« Introuvable »` | 404 |
| Action refusée (résiliation non autorisée, etc.) | `« Action impossible »` | 422 |
| Trop de demandes de code | `« Trop de demandes »` | 429 |
| Connexion par code indisponible | `« Service indisponible »` | 503 |
| Déconnexion | `« Vous êtes déconnecté »` | 200 |

---

## 9. Configurer le portail depuis la console

`GET /admin/projects/{projectId}/portal` — permission `portal:write`.

| Champ | Ce qu'il fait |
|---|---|
| `« Nom affiché »` | obligatoire, 80 caractères. Apparaît partout. |
| `« Couleur de la marque »` | format `#rrggbb`, avec un aperçu. Le contraste est recalculé pour rester lisible. |
| `« Langue »` | `fr` par défaut |
| `« Logo (URL https) »` | doit être hébergé sur une **origine autorisée** |
| `« Aide (URL https ou mailto:) »` | le lien `« Besoin d'aide ? »` |
| `« Adresses de retour autorisées »` | **une URL par ligne**. Le lien `« Retour à {nom} »` n'apparaît que si la liste n'est pas vide. |
| `« Souscrire, changer d'offre et renouveler »` | autorise les actions du chapitre 4 |
| `« Résilier à la fin de la période (et reprendre le renouvellement) »` | idem |
| `« Proposer la connexion par code »` | **désactivé par défaut** |
| `« Session WhatsApp d'envoi »` | **obligatoire** si la connexion par code est activée. C'est le nom d'une session créée dans `« Sessions WhatsApp »`. |
| `« Restreindre les moyens de paiement »` | cases : MTN Mobile Money, Orange Money, Carte bancaire. Vide = tous les canaux routables. |

`« Enregistrer »` → `« Réglages du portail enregistrés. »` Chaque sauvegarde est auditée
(`portal.settings_changed`), avec l'avant et l'après.

> **Une origine d'« assets » autorisée** est une origine `https` exacte, sans chemin. Si
> l'URL du logo n'est pas sur une origine déclarée, **le logo n'est pas affiché** — et le
> lien d'aide non plus. Ce champ se règle par l'API (`PUT /v1/portal/settings`), pas par
> l'écran de la console.

---

## 10. Ce que le client ne voit pas

| Réservé à | Éléments |
|---|---|
| **La console opérateur** | la fiche client complète (identité non masquée), les factures en brouillon, le journal d'audit, la file des lettres mortes des webhooks, la réconciliation, les exceptions d'argent, les corrections du grand livre, la gestion des opérateurs |
| **L'API du projet** | `POST /v1/portal/sessions`, `GET` et `PUT /v1/portal/settings`, les clés d'API, la configuration des canaux de paiement, le catalogue des offres |
| **Le client final** | rien d'autre que ce qui est décrit ci-dessus |

Le client **ne peut pas** : modifier son profil, télécharger une facture en PDF, consulter
un reçu téléchargeable, créer un client, changer une offre du catalogue, configurer un
prestataire de paiement.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Routes du portail | `packages/portal/routes/web.php`, `packages/portal/routes/api.php` |
| Contrôleur des pages | `packages/portal/src/Presentation/Http/Controllers/PortalPagesController.php` |
| Connexion par code | `packages/portal/src/Presentation/Http/Controllers/DirectLoginController.php`, `…/Application/UseCase/DirectLoginService.php` |
| Règles du code | `packages/portal/src/Domain/Model/PortalLoginCode.php` |
| Réglages | `packages/portal/src/Domain/Model/PortalSettings.php`, `…/Application/UseCase/PortalSettingsService.php` |
| En-têtes de sécurité | `packages/portal/src/Presentation/Http/Middleware/PortalSecurityHeaders.php` |
| Session et cookie | `packages/portal/src/Presentation/Http/Middleware/AuthenticatePortal.php`, `…/PortalCookie.php` |
| Écran console | `app/Http/Controllers/Admin/ProjectCommerceController.php` (`portal`), `resources/views/admin/billing/portal.blade.php` |
| Règles | `docs/adr/0075-portail-client-heberge.md`, `0097-portail-un-appel-assets-sans-build-paiement-sans-configuration.md`, `0100-portail-connexion-par-code-arete-notification.md` |
