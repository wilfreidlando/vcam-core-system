<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Which routes to document. String or array form; use Scramble::routes() for custom selection.
     *
     * 'api_path' => [
     *     'include' => 'api',
     *     'exclude' => ['api/internal'],
     * ],
     *
     * Without *, patterns match path segments (api matches api and api/users, not apiary).
     * With *, Str::is is used (e.g. api/v*).
     *
     * One static include → default server is /{include} and paths are stripped (/users).
     * Multiple includes or wildcards → server defaults to / and paths stay full (/api/users).
     * Override with `servers`, or use Scramble::registerApi() for separate bases.
     */
    // ADR-0028: no default `/api` prefix — every module owns its own
    // versioned prefix under `bootstrap/app.php`'s `apiPrefix: ''`. `v1`
    // matches every endpoint documented so far (whoami, wallet balance).
    // `/webhooks/*` (payment provider inbound) is deliberately excluded:
    // it's not a consumer-facing API surface, it's a provider integration.
    // `v1/admin` and `v1/operators` are excluded too (ADR-0049): the
    // operator/console plane isn't a third-party integration surface —
    // it gets its own separate document, registered in
    // AppServiceProvider::configureAdminApiDocumentation().
    'api_path' => [
        'include' => 'v1',
        'exclude' => ['v1/admin', 'v1/operators'],
    ],

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', 'v1'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => 'Enterprise Core System — the cross-cutting capabilities (identity, ledger, '.
            'wallet, payment, notification, webhook) shared across every product. '.
            'Authenticate with `Authorization: Bearer pk_<env>_<id>.<secret>` — see `GET /v1/whoami`. '.
            "\n\n".
            '## Reading this document '.
            "\n\n".
            '- Every request body attribute has a description and a realistic example. `required` is marked. '.
            '- Every operation carries an `x-required-scopes` field listing the API key scopes the caller must hold. '.
            '- Every monetary POST under `/v1/payments/*` requires an `Idempotency-Key` header (cached response on replay, `409` on conflict). '.
            "\n\n".
            '## Outbound events (Core → caller) '.
            "\n\n".
            'The Core emits events on two planes — register `POST /v1/webhook-endpoints` to subscribe, '.
            'or supply `callback_url` on `POST /v1/payments` for a per-payment delivery: '.
            "\n\n".
            '- `WebhookEvent` — envelope `{id, type, data}` POSTed to subscribed endpoints, signed HMAC-SHA256 '.
            '(headers `X-Signature`, `X-Timestamp`, `X-Event-Id`, `X-Event-Type`). '.
            '- `WebhookEventType` — the closed enum of event types the Core emits (`payment.status_changed`, '.
            '`notification.status_changed`, `notification.failed`, `wallet.credited`, `wallet.debited`). '.
            '- `PaymentCallbackEvent` — the body POSTed to a per-payment `callback_url`. Same signature scheme, '.
            'per-payment secret returned once in the initiation response. '.
            "\n\n".
            '## Errors '.
            "\n\n".
            'Every error response is `application/problem+json` (RFC 7807) with `{type, title, status, detail, correlation_id}`. '.
            '422 from a `FormRequest` adds an `errors: {field: [msg]}` map. Quote `correlation_id` when contacting support.',
    ],

    'ui' => [
        'title' => 'Core System API',
    ],

    /*
     * Load Scramble's development tools on documentation pages. An explicit
     * SCRAMBLE_DEV_TOOLS value takes precedence over APP_DEBUG.
     */
    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [
        // Declared here (not via `Scramble::registerExtension` in
        // `AppServiceProvider::boot`) because Scramble's package boot
        // snapshots `Scramble::$extensions` BEFORE the app providers
        // boot — anything added through the static helper is too late
        // and silently never fires. See `app/OpenApi/IdempotencyHeaderExtension`.
        App\OpenApi\IdempotencyHeaderExtension::class,
        App\OpenApi\ValidationExceptionResponseExtension::class,
        App\OpenApi\RequiredScopesExtension::class,
    ],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */
    // `project.auth` is the alias bootstrap/app.php binds to
    // ResolveProjectMiddleware (ADR-0033 §3) — every route that carries it
    // requires `Authorization: Bearer pk_<env>_<id>.<secret>` (ADR-0028's
    // API key scheme, not a JWT/OAuth bearer).
    'security_strategy' => [
        Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
        [
            'middleware' => ['project.auth'],
            'scheme' => Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer', 'pk_<env>_<id>.<secret>'),
        ],
    ],
];
