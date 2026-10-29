<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Http\Middleware\AdminSecurityHeaders;
use Core\Portal\Presentation\Http\Middleware\PortalSecurityHeaders;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Garde-fou G-1 de l'annexe sécurité (recette du 2026-10-29) : « toute réponse HTML du Core porte
 * ses en-têtes de sécurité ». Le portail avait son middleware et son test ; la console, qui bouge
 * l'argent, n'avait rien — parce que la règle était « un plan », pas « tous les plans HTML ».
 *
 * Ce test regarde la TABLE DE ROUTAGE, pas cinq pages choisies : une route de la console ajoutée
 * demain sans l'alias `admin.headers` fait échouer le build, avec le nom de la route.
 */
final class HtmlPlanesCarrySecurityHeadersTest extends TestCase
{
    #[Test]
    public function every_console_route_carries_the_console_headers(): void
    {
        $missing = $this->routesMatching('admin', static fn (array $middleware): bool => in_array('admin.headers', $middleware, true)
            || in_array(AdminSecurityHeaders::class, $middleware, true));

        self::assertSame([], $missing, "These console routes have no `admin.headers` middleware — the page can be framed and cached:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function every_portal_route_carries_the_portal_headers(): void
    {
        $missing = $this->routesMatching('portal', static fn (array $middleware): bool => in_array(PortalSecurityHeaders::class, $middleware, true));

        self::assertSame([], $missing, "These portal routes have no PortalSecurityHeaders middleware:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function the_two_planes_are_actually_found_by_the_scan(): void
    {
        // A scan that finds nothing passes every test above: prove it sees both planes.
        self::assertGreaterThan(50, count($this->routesUnder('admin')), 'the console routes were not found');
        self::assertGreaterThan(5, count($this->routesUnder('portal')), 'the portal routes were not found');
    }

    /**
     * @param callable(array<int, string>): bool $isProtected
     * @return list<string> the `METHOD uri` of the routes under $prefix that are NOT protected
     */
    private function routesMatching(string $prefix, callable $isProtected): array
    {
        $missing = [];
        foreach ($this->routesUnder($prefix) as $route) {
            /** @var array<int, string> $middleware */
            $middleware = array_map(strval(...), $route->gatherMiddleware());
            if (! $isProtected($middleware)) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        return $missing;
    }

    /**
     * @return list<LaravelRoute>
     */
    private function routesUnder(string $prefix): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }
}
