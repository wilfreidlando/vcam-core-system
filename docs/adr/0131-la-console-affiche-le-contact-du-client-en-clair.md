# ADR-0131 — La console affiche le contact du client en clair

- **Statut** : Accepté
- **Date** : 2026-11-22
- **Décideurs** : commanditaire (« Contact en clair dans la console »), comité d'architecture
- **Dérives concernées** : D3 données/PII
- **Amende** : ADR-0083 §3 (la console masque le contact), ADR-0123 §3 (le noyau partagé garde le masque d'affichage) et §4 (la console masque par défaut)
- **Précise** : ADR-0123 §1 (les données client sont conservées en clair), ADR-0070 §1

## Contexte

ADR-0083 §3 disait : « la console n'affiche jamais les données personnelles en
clair ». Le contact d'un client y était donc montré masqué (`a***@d***.cm`),
et le démasquage devait rester « un acte audité » — un acte que le Core n'a
jamais proposé.

ADR-0123 a supprimé le chiffrement applicatif : le nom, l'e-mail et le
téléphone sont conservés **en clair**, et le commanditaire a noté que
l'application métier — l'auto-école, toute application cliente — détient déjà
ces mêmes valeurs en clair, dans sa propre base. Le Core est un miroir : il ne
protège pas une donnée que l'application expose déjà.

Il en restait une traces dans la console, et elle s'est vue à l'usage :

1. **Le masque n'est plus une protection, c'est un obstacle.** L'opérateur ne
   reconnaît pas son client : il voit `a***@d***.cm`, il doit ouvrir un autre
   écran, l'application, ou son GSM, pour savoir à qui il parle. Le commanditaire
   a tranché : « contact en clair dans la console ».
2. **La fiche client ne montrait pas le contact du tout.** Il n'y avait rien à
   démasquer, et donc rien à regarder : la fiche — l'écran où l'on va chercher
   le contact — n'affichait ni e-mail ni téléphone.
3. **Un masque qui ne protège plus rien est un écran de plus.** Le noyau
   partagé gardait `Privacy\PersonalDataMask` pour cet usage unique ; il
   disparaît avec lui.

## Décision

**Décision 1 — la console montre le contact en clair.**

La liste des clients, la fiche client et le formulaire de déclaration
affichent l'e-mail et le téléphone tels que le Core les conserve. Un client
sans contact porte « — », comme avant.

**Décision 2 — la fiche client montre le contact.**

C'est une conséquence directe de la décision 1, et une correction : la fiche
ne montrait ni e-mail ni téléphone. Elle affiche les deux, sous le nom du
client.

**Décision 3 — le masque d'affichage est retiré du noyau partagé.**

`Core\Support\Privacy\PersonalDataMask` et son test sont supprimés. Un masque
qui ne protège plus rien n'est qu'un écran de plus à maintenir (la même
logique que le chiffreur retiré par ADR-0123 §3).

**Décision 4 — ce qui ne change pas.**

- l'API ne rend l'e-mail et le téléphone qu'à un appelant portant
  `customers:read` (ADR-0083 §3) ;
- le journal d'audit et les journaux techniques ne portent **aucune** valeur
  personnelle (ADR-0083 §4, S-17) ;
- l'effacement vide l'identité — nom, contact, métadonnées (ADR-0083 §6) ;
- la charge utile des événements n'en porte pas davantage (ADR-0070 §3) ;
- le portail client montre à chaque client **son propre** contact, et rien
  d'autre.

**Décision 5 — la forme acceptée du locataire continue de remplacer les champs
du Core.**

Ce que la validation exige et ce que l'écran montre ne peuvent pas diverger :
une clé déclarée qui porte le nom du client (ADR-0122 §5) remplace le champ
`display_name` du formulaire **et** sa règle de validation. Le garde-fou est le
test `TenantSchemaConsoleTest` : un formulaire complet est accepté, et le
refus revient sous le champ réellement rempli.

## Alternatives écartées

- **Garder le masque, ajouter un bouton « démasquer » audité.** Rejeté par le
  commanditaire : l'opérateur n'a pas à lever un voile sur une donnée que
  l'application métier expose déjà, et le geste supplémentaire est une
  friction pure. L'action auditée n'est pas écrite : elle reste à faire si la
  demande revient.
- **Masquer dans la liste, montrer dans la fiche.** Rejeté : les deux écrans
  servent à la même chose — reconnaître un client — et l'inverse de ce qui
  est demandé serait illisible.
- **Chiffrer de nouveau le contact.** Hors sujet : ADR-0123 a tranché, et la
  raison tient toujours (l'application métier détient la valeur en clair).
- **Ne montrer que le nom.** Rejeté : le nom displayed est lui aussi une donnée
  personnelle ; masquer l'e-mail mais pas le nom ne protège rien.

## Conséquences

- La console expose une donnée personnelle en clair. C'est la contrepartie
  assumée : une capture d'écran de la console expose des contacts. Le compte
  console est un compte à privilèges (MFA obligatoire, ADR-0128) et la portée
  `customers:read` borne ce qu'il voit.
- Le noyau partagé perd une classe et son test ; `packages/support/README.md`
  décrit désormais ce qui reste.
- Un test de console épingle l'affichage en clair
  (`tests/Feature/Admin/BillingConsoleWebFlowTest.php`) : si le masque revient,
  le test tombe.
- Le masque d'affichage n'existe plus : le retrouver demande de l'écrire à
  nouveau. C'est voulu — il ne doit pas être réintroduit « au cas où ».

## Vérification

- Test : la liste des clients montre l'e-mail en clair, et ne montre plus
  `c***@a***.test`.
- Test : le formulaire de modification rappelle la valeur actuelle en clair.
- Test : la fiche client montre l'e-mail et le téléphone.
- Test : `packages/support` ne contient plus de classe de masquage.
- Test : un formulaire de déclaration complet est accepté quand la forme en
  vigueur porte le nom du client (ADR-0122 §5, décision 5).
