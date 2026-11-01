<!doctype html>
{{-- A14 — application française : la langue du document est le français (SC 3.1.2). --}}
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $config->get('ui.title') ?? config('app.name') . ' - API Docs' }}</title>
    @include('scramble::dev-tools', ['renderer' => 'scalar'])
</head>
<body>
<div id="app"></div>
{{-- Audit 2026-10-29 #14 / G-1 — the bundle is third-party code served on the API's own
     origin. The URL used to point at `@latest`: a mutable dependency, so the CDN could serve
     anything. It is now pinned to an exact version, and `integrity` pins the content
     cryptographically. A compromised CDN can no longer execute on this origin. --}}
<script src="{{ $config->renderer()->get('cdn', 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.39.1') }}"
        integrity="sha384-QqjCA8iZCoyaj50XRDKr3ToEbXY2SlsYWBnR/l8wn8bDs2UK1M45+Ut8WLjUCVDv"
        crossorigin="anonymous"></script>

<script>
    const CSRF_TOKEN_COOKIE_KEY = "XSRF-TOKEN";
    const CSRF_TOKEN_HEADER_KEY = "X-XSRF-TOKEN";
    const getCookieValue = (key) => {
        const cookie = document.cookie.split(';').find((cookie) => cookie.trim().startsWith(key));
        return cookie?.split("=")[1];
    };

    Scalar.createApiReference('#app', {
        content: @json($spec),
        ...@json($config->renderer()->all(except: ['cdn', 'credentials'])),
        onBeforeRequest: ({ requestBuilder }) => {
            requestBuilder.headers.set(CSRF_TOKEN_HEADER_KEY, decodeURIComponent(getCookieValue(CSRF_TOKEN_COOKIE_KEY)))
        },
        customFetch: (input, init) => {
            return window.fetch(input, { ...init, credentials: @json($config->renderer()->get('credentials', 'include')) })
        }
    })
</script>
</body>
</html>
