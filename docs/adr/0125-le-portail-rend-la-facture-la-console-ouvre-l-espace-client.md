# ADR-0125 — Le portail rend la facture du client ; la console ouvre l'espace du client

- **Statut** : Accepté
- **Date** : 2026-11-13
- **Décideurs** : commanditaire (« il faut le lien à copier quelque part qui permet de se connecter au portail de cette façon », « il n'y a pas un endroit où on télécharge la facture avec l'en-tête »), comité d'architecture
- **Précise** : ADR-0075 (le portail client), ADR-0098 (le libre-service du portail), ADR-0073 §5 (le document imprimable), ADR-0100 (la connexion par code), ADR-0033 §4 (la nature ouverte des comptes), ADR-0104 (la console sans JavaScript), ADR-0057 (les sessions globales)
- **Amende** : —

## Contexte

Deux gestes manquent, et l'opérateur les a cherchés dans l'écran.

**Le document de facture.** ADR-0073 §5 a prévu le document imprimable : les
lignes figées de la facture, rendues avec l'en-tête du projet
(`documentBranding`). Le code existe, la route existe — `GET
/v1/invoices/{id}/document` — mais elle n'est atteignable **que par l'API**,
avec une clé de projet. Ni la console ni le portail ne l'offrent. Le client ne
peut pas récupérer sa facture ; l'opérateur ne peut pas l'imprimer.

**L'entrée dans l'espace du client.** ADR-0075 §1 a fait du portail un
appel unique : `POST /v1/portal/sessions` ouvre une session et rend le lien à
usage unique (`/portal/enter/{token}`). C'est le geste de l'**application
cliente**, qui envoie son client vers son espace. L'opérateur, lui, n'a rien :
la fiche client de la console n'a ni bouton ni lien. Il ne peut pas donner un
accès à un client qui n'a pas d'application cliente — ce qui est le cas
habituel.

Et la connexion par code (ADR-0100) n'est visible nulle part : le projet
peut l'activer, la session d'envoi se choisit dans les réglages du portail
(celle du projet **ou** une session globale, ADR-0057), mais l'opérateur ne
voit ni l'adresse à donner au client, ni la session qui sera utilisée.

## Décision

**Décision 1 — le portail rend la facture du client.**

La facture du portail est **le même document** que celui de l'API : les lignes
figées, l'en-tête du projet, les totaux. Il est rendu par le contrat publié
`Core\Billing\Contracts\GetInvoiceDocument`, donc la console, l'API et le
portail ne peuvent pas diverger.

- `GET /portal/invoices/{id}/document` : derrière l'authentification du
  portail, et **résolu dans le client de la session** — la facture d'un autre
  client n'est pas trouvée (404, jamais 403).
- `GET /admin/projects/{projectId}/invoices/{id}/document` : la même chose
  pour l'opérateur, qui imprime depuis son navigateur.

**Décision 2 — le rendu du document est un adaptateur, pas une présentation.**

La règle de couches (`deptrac-layers.yaml`) interdit à la Présentation
d'atteindre l'Infrastructure, et à l'Application de connaître autre chose que
le Domaine et les Contrats. Le rendu HTML du document descend donc en
`Infrastructure/Documents`, derrière le port
`Core\Billing\Application\Port\InvoiceDocumentRenderer` ; le cas d'usage
`GetInvoiceDocumentUseCase` le consomme, et le contrôleur de l'API comme la
console et le portail consomment le **contrat**. Le PDF reste hors périmètre
(ADR-0094 §14 : il demande une bibliothèque).

Et la mise en forme du document vit dans une **feuille same-origin**
(`public/assets/invoice-document.css`), non dans un `<style>` inline : la
politique de sécurité de contenu du portail est stricte (`style-src 'self'`),
et le inline y est refusé. Sans cette feuille, le client lirait une facture
sans mise en forme.

**Décision 3 — la console ouvre l'espace du client et rend le lien à copier.**

Sur la fiche client, un geste **« Ouvrir l'espace du client »** ouvre une
session et rend le **lien à copier**, avec sa date d'expiration et le rappel
qu'il est à usage unique. Le lien est un secret : il ne passe jamais dans un
journal ni dans l'audit (ADR-0083 §4) ; le journal ne retient que l'acte.

La console est **sans JavaScript** (ADR-0104) : le lien est dans un champ en
lecture seule, sélectionnable — pas un bouton « copier » qui ne ferait rien.

**Décision 4 — la connexion par code est montrée à côté.**

Quand le projet l'a activée (ADR-0100), la même page montre l'adresse
`/portal/{projectId}/login` et **la session d'envoi utilisée**, en disant si
c'est celle du projet ou une **session globale** (utilisable par tous les
projets — ADR-0057). L'opérateur n'a pas à savoir que « globale » existe :
il doit voir ce qui va se passer.

Cette page est **la fiche du client**, et le bloc y figure en permanence —
pas seulement dans la réponse du geste qui ouvre l'espace. L'opérateur doit
pouvoir lire l'adresse et la session d'envoi **avant** de donner quoi que ce
soit à un client ; l'observer après coup ne lui sert à rien.

**Décision 5 — le modèle du fichier d'import se télécharge.**

L'import exige un en-tête exact (`external_ref,display_name,email,phone`) :
un fichier qui ne le porte pas est **refusé**, jamais interprété. L'en-tête
est dit en prose sur la page, mais **le modèle se télécharge** — un fichier
vide de données, prêt à être rempli, produit par la **même source** que le
lecteur : les deux ne peuvent plus diverger.

**Décision 6 — les comptes d'un projet se voient, et l'argent du projet se
distingue de l'argent d'un client.**

Les comptes sont créés **à la demande** par les modules qui en ont besoin
(ADR-0033 §4 — la nature est ouverte, ce n'est pas un vocabulaire que
l'opérateur choisit) : le Core crée les comptes, l'opérateur ne les crée
jamais. Mais ils doivent être **visibles** : la balance ne liste que les
comptes qui ont bougé, et la nature brute (`customer_receivable`,
`clearing:mycoolpay`) n'est pas une langue.

La console montre donc **tous** les comptes du projet, avec un libellé
français, et — c'est le point — en distinguant :

- **l'argent du projet** : « Encaissements — MyCoolPay », « Recettes »,
  « Sorties de trésorerie » ;
- **l'argent d'un client** : « Portefeuille de AE0004 », « Créances clients —
  AE0004 ».

Un compte `wallet` est **toujours** le portefeuille d'un client ; l'argent du
projet n'est jamais dans un `wallet` : il est chez le prestataire
(`clearing:<prestataire>`) et dans les sorties (`withdrawal`). Une référence
non résolue se dit telle quelle (« Portefeuille inconnu ») — jamais un
identifiant brut à l'écran.

**Décision 7 — le relevé d'un compte sort de l'ombre.**

`AccountStatement` était implémenté et lié, jamais appelé. La liste des
comptes mène à ses mouvements : l'opérateur voit d'où vient un solde.

La console étant le **premier** appelant de ce port, le port prend désormais
l'`AccessScope` en **paramètre requis** (ADR-0069 §2, garde-fou G16) : un
relevé demandé hors de la portée rend une page **vide**, jamais le relevé d'un
autre locataire. La règle PHPStan qui surveille les ports de lecture a appris
le nom `*Statement` — sans quoi le port aurait pu être appelé depuis la
console sans portée, sans que rien ne le remarque.

## Conséquences

- Le client récupère sa facture avec son en-tête, sans que l'opérateur ait à
  l'imprimer pour lui.
- L'opérateur donne un accès à l'espace client sans application cliente, et
  sait quel lien il donne, pour combien de temps, et par quel chemin.
- L'import ne se devine plus : le fichier se télécharge.
- La comptabilité du projet se lit : les comptes ont un nom, et l'argent du
  projet ne se confond plus avec celui d'un client.

## Alternatives écartées

- **Un PDF** : il demande une bibliothèque (ADR-0094 §14) ; le HTML
  imprimable est déjà là et fait le travail.
- **Un bouton « copier » en JavaScript** : la console est sans JavaScript
  (ADR-0104) ; un champ en lecture seule se copie partout.
- **Laisser le portail rendre une facture sans en-tête** : le client verrait
  un document différent de celui que l'opérateur a imprimé.
- **Un écran de création de compte** : la nature d'un compte est l'affaire du
  module qui l'utilise (ADR-0033 §4) ; un opérateur qui choisirait des
  natures créerait des comptes que rien n'utilisera.
- **Envoyer le lien portail par e-mail ou WhatsApp depuis le Core** : le Core
  ne tient pas le canal du client ; l'opérateur l'envoie lui-même.

## Vérification

- `packages/billing/tests/Integration/` — le contrat `GetInvoiceDocument` rend
  le même document que l'API, et une facture d'un autre projet n'est pas
  trouvée.
- `packages/portal/tests/` — la route du portail rend le document **du client
  de la session** (et refuse celui d'un autre client).
- `tests/Feature/Admin/` — la console rend la facture imprimable, la session
  portail (avec le lien à copier et son expiration), le modèle d'import, et
  liste les comptes du projet en distinguant l'argent du projet de celui d'un
  client.
