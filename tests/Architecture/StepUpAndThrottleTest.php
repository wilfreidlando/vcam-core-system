<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Écarts #5 et #17 de la recette 2026-10-29 (annexe sécurité, G-4 et G-12) — une action
 * sensible de la console ne s'exécute ni sans preuve TOTP récente (`operator.step_up`)
 * ni sans plafond (`throttle:console-sensitive`).
 *
 * Le garde-fou est structurel : il parcourt la table de routage. Une nouvelle route
 * mutante dans une famille sensible (clés d'API, webhooks, opérateurs, membres,
 * exceptions d'argent, corrections du grand livre, effacement) doit être classée ici,
 * sinon le build échoue avant la revue.
 */
#[Group('arch')]
final class StepUpAndThrottleTest extends TestCase
{
    private const STEP_UP = 'operator.step_up';

    private const THROTTLE = 'throttle:console-sensitive';

    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Routes mutantes qui exigent l'élévation ET le plafond. */
    private const SENSITIVE = [
        'admin.money-exceptions.approve',
        'admin.money-exceptions.resolve',
        'admin.ledger-adjustments.approve',
        'admin.projects.api-keys.store',
        'admin.projects.api-keys.rotate',
        'admin.projects.api-keys.revoke',
        'admin.projects.webhook-endpoints.store',
        'admin.projects.webhook-endpoints.update',
        'admin.projects.webhook-endpoints.rotate-secret',
        'admin.projects.webhook-endpoints.revoke-previous-secret',
        'admin.projects.customers.erase.web',
        'admin.operators.store',
        'admin.operators.revoke.web',
        'admin.operators.update.web',
        'admin.organizations.members.store',
        'admin.organizations.members.revoke.web',
        'admin.organizations.members.role.web',
    ];

    /** Routes mutantes plafonnées (création, suppression, refus) sans élévation. */
    private const THROTTLED_ONLY = [
        'admin.organizations.store',
        'admin.projects.store',
        'admin.projects.customers.store',
        'admin.ledger-adjustments.store',
        'admin.ledger-adjustments.reject',
        'admin.money-exceptions.reject',
        'admin.channel-sessions.store',
        'admin.channel-sessions.delete',
        'admin.projects.channel-sessions.store',
        'admin.projects.channel-sessions.delete',
        'admin.projects.provider-applications.store',
        'admin.projects.provider-applications.delete',
        'admin.projects.webhook-endpoints.activate',
        'admin.projects.webhook-endpoints.deactivate',
        'admin.projects.exports.store',
        'admin.projects.exports.download',
    ];

    /** Familles de routes dont toute écriture doit être classée dans l'une des deux listes. */
    private const SENSITIVE_FAMILY = '#/(api-keys|erase|operators|members|webhook-endpoints|money-exceptions|ledger-adjustments)(/|$)#';

    #[Test]
    public function every_sensitive_console_mutation_requires_a_step_up_and_is_throttled(): void
    {
        foreach (self::SENSITIVE as $name) {
            $route = $this->route($name);
            $middleware = $route->gatherMiddleware();

            self::assertContains(self::STEP_UP, $middleware, "{$name} doit porter operator.step_up (écart #5).");
            self::assertContains(self::THROTTLE, $middleware, "{$name} doit être plafonnée (écart #17).");
            self::assertNotEmpty(array_filter(self::WRITES, static fn (string $m): bool => in_array($m, $route->methods(), true)), "{$name} doit être une écriture.");
        }
    }

    #[Test]
    public function the_step_up_comes_after_the_operator_session(): void
    {
        foreach (self::SENSITIVE as $name) {
            $middleware = $this->route($name)->gatherMiddleware();

            self::assertLessThan(
                array_search(self::STEP_UP, $middleware, true),
                array_search('operator.session', $middleware, true),
                "{$name} : operator.step_up lit l'opérateur que operator.session résout, il doit venir après.",
            );
        }
    }

    #[Test]
    public function every_creation_revocation_or_refusal_is_throttled(): void
    {
        foreach (self::THROTTLED_ONLY as $name) {
            self::assertContains(self::THROTTLE, $this->route($name)->gatherMiddleware(), "{$name} doit être plafonnée (écart #17).");
        }
    }

    #[Test]
    public function the_step_up_screen_exists_and_caps_the_code_attempts(): void
    {
        $show = $this->route('admin.step-up.show');
        $verify = $this->route('admin.step-up.verify');

        self::assertContains('GET', $show->methods());
        self::assertContains('POST', $verify->methods());
        self::assertSame('admin/step-up', $verify->uri());
        self::assertContains('operator.session', $verify->gatherMiddleware(), 'seul un opérateur connecté peut s\'élever');
        self::assertContains('throttle:console-step-up', $verify->gatherMiddleware(), 'la ressaisie du code est un oracle : plafond obligatoire');
    }

    #[Test]
    public function a_new_write_in_a_sensitive_family_must_be_classified(): void
    {
        $classified = [...self::SENSITIVE, ...self::THROTTLED_ONLY];
        $unclassified = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin/')) {
                continue;
            }
            if (array_filter(self::WRITES, static fn (string $m): bool => in_array($m, $route->methods(), true)) === []) {
                continue;
            }
            if (preg_match(self::SENSITIVE_FAMILY, '/'.$route->uri()) !== 1) {
                continue;
            }
            if (! in_array($route->getName(), $classified, true)) {
                $unclassified[] = $route->uri().' ('.(string) $route->getName().')';
            }
        }

        self::assertSame(
            [],
            $unclassified,
            "Ces écritures de la console touchent une famille sensible sans être classées (SENSITIVE ou THROTTLED_ONLY) :\n".implode("\n", $unclassified),
        );
    }

    private function route(string $name): Route
    {
        $route = RouteFacade::getRoutes()->getByName($name);
        self::assertInstanceOf(Route::class, $route, "La route {$name} n'existe pas.");

        return $route;
    }
}
