# ADR-0037 — Registre provider typé par domaine + détection des doublons

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Précède / amende** : [ADR-0026](0026-integration-providers-externes.md) § découplage, [ADR-0034](0034-socle-integration-scope-structure.md) § ProviderRegistry

## Contexte

Le `TaggedProviderRegistry` (du fondation `Core\Support\Integration\Provider`) indexe les gateways par `providerCode()->toString()` à partir du tag Laravel `provider.gateway`. Une revue du code livré pour l'intégration MyCoolPay a fait apparaître deux problèmes réels :

### Problème 1 — Le registre retourne le type générique

`ProviderRegistry::gatewayFor(ProviderCode)` est typé `ProviderGateway`. Un use case du module `Payment` qui veut appeler `->initiate(...)` doit donc faire :

```php
$gw = $registry->gatewayFor($code);
if (! $gw instanceof PaymentGateway) {
    throw new \LogicException(...);
}
return $gw->initiate($cmd);
```

C'est laid, fragile, et non couvert par PHPStan. PHP n'a pas de generics runtime, donc le fondation ne peut pas narrower le type.

### Problème 2 — Doublons écrasés silencieusement

Le `TaggedProviderRegistry` faisait `$byCode[$code] = $gateway` sans vérifier si la clé existait déjà. Si demain le module `Notification` enregistre un adaptateur `orange` (Orange Money est utilisé pour le paiement **et** pour l'envoi d'USSD/SMS en Afrique de l'Ouest/Centrale), le second tag écraserait silencieusement le premier. Le routing des requêtes dérive entre deux déploiements sans que personne ne s'en aperçoive.

## Décision

### Registre typé par domaine (problème 1)

Chaque module qui a des adaptateurs providers définit un wrapper typé dans `Contracts/`. Le wrapper délègue tout au `ProviderRegistry` générique, sauf `gatewayFor()` qui fait le cast avec un message d'erreur clair si la classe ne satisfait pas l'interface spécifique du module.

Pour le module `Payment` :
- `Core\Payment\Contracts\PaymentGatewayRegistry` — interface typée avec `gatewayFor(): PaymentGateway`
- `Core\Payment\Infrastructure\Provider\PaymentGatewayRegistryImpl` — impl qui prend le `ProviderRegistry` générique dans son constructeur
- `UnsupportedPaymentGatewayException` — levée quand le cast échoue

Les use cases du module dépendent uniquement de `PaymentGatewayRegistry`. Le type narrowing est statique (l'interface `PaymentGateway` expose les méthodes paiement) — pas de `instanceof` au site d'appel.

### Détection des doublons (problème 2)

Le `TaggedProviderRegistry` lève maintenant `DuplicateProviderCodeException` au boot si deux gateways taggés partagent le même `providerCode()`. Le message d'erreur cite les deux classes en collision pour que l'opérateur puisse les distinguer en un coup d'œil.

## Conséquences

- Les use cases `Payment` au Lot 3 dépendront de `PaymentGatewayRegistry`, pas de `ProviderRegistry`. Pas de `instanceof` au site d'appel.
- Tout autre module à adaptateurs providers (`Notification`, `Sms`, …) suivra le même pattern : un wrapper typé par module. C'est une décision répétée mais mécanique (10 lignes par wrapper).
- La détection des doublons fait échouer l'application au boot si deux providers partagent un code — c'est un échec rapide et bruyant, bien meilleur qu'un routing silencieux.
- L'espace de noms reste plat dans le `TaggedProviderRegistry` (les doublons cross-modules sont bloqués par l'exception). Pour les rares cas où deux modules ont besoin du même identifiant logique, le code doit préfixer le code (par ex. `payment-orange` vs `notification-orange`).

## Alternatives écartées

- **Préfixer tous les codes provider par domaine** (`payment-my-coolpay`, `notification-orange`) — ajoute du bruit dans la configuration et les logs. L'exception est suffisante pour signaler le vrai problème.
- **Injecter le namespace de domaine dans le tag** (`provider.gateway.payment`) — ajoute de la complexité au foundation et complique l'API des modules. La détection des doublons par code-string est plus simple.
- **Faire un cast implicite sans message d'erreur** — le `instanceof` qui retourne `null` silencieusement reproduit le bug originel (null → NPE au site d'appel). L'erreur explicite est meilleure.
