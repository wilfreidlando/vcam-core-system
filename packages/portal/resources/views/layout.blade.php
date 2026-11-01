<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Espace client')@isset($principal) · {{ $principal->branding->displayName }}@endisset</title>
    <link rel="stylesheet" href="/assets/portal/portal.css">
    <link rel="stylesheet" href="/portal/theme.css{{ isset($themeProject) ? '?project='.$themeProject : '' }}">
</head>
<body @class(['shell' => isset($principal)])>
@isset($principal)
    <a class="skip" href="#main">Aller au contenu</a>

    {{-- The mobile drawer is a checkbox: the portal ships no script. --}}
    <input type="checkbox" id="nav-toggle" class="nav-toggle" role="switch" aria-label="Menu de navigation" aria-controls="sidebar">

    <header class="mobilebar">
        <label for="nav-toggle" class="iconbtn" title="Menu">@include('portal::partials.icon', ['name' => 'menu'])<span class="sr-only">Menu</span></label>
        <span class="brand__name" title="{{ $principal->branding->displayName }}">{{ $principal->branding->displayName }}</span>
    </header>

    <label for="nav-toggle" class="scrim" aria-hidden="true"></label>

    <div class="sidebar" id="sidebar">
        <a class="brand" href="/portal">
            @if ($principal->branding->logoUrl)
                <img class="brand__logo" src="{{ $principal->branding->logoUrl }}" alt="">
            @else
                <span class="brand__mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($principal->branding->displayName, 0, 1)) }}</span>
            @endif
            <span class="brand__name" title="{{ $principal->branding->displayName }}">{{ $principal->branding->displayName }}</span>
        </a>

        <nav class="nav" aria-label="Navigation principale">
            @php
                // "page" only on the exact page; "true" on a section that holds the current page.
                $items = [
                    ['/portal', 'home', 'Accueil'],
                    ['/portal/subscriptions', 'subscription', 'Mon abonnement'],
                    ['/portal/plans', 'plans', 'Offres et tarifs'],
                    ['/portal/invoices', 'invoices', 'Factures'],
                    ['/portal/payments', 'payments', 'Paiements'],
                    ['/portal/usage', 'usage', 'Utilisation'],
                    ['/portal/profile', 'profile', 'Mon profil'],
                ];
            @endphp
            @foreach ($items as [$href, $icon, $label])
                @php
                    $path = ltrim($href, '/');
                    $current = request()->is($path) ? 'page' : ($href !== '/portal' && request()->is($path.'/*') ? 'true' : null);
                @endphp
                <a href="{{ $href }}" @class(['nav__item', 'is-active' => $current !== null]) @if($current) aria-current="{{ $current }}" @endif>
                    @include('portal::partials.icon', ['name' => $icon])
                    <span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>

        <footer class="sidebar__foot">
            @if ($principal->returnUrl)
                <a class="nav__item nav__item--quiet" href="{{ $principal->returnUrl }}">
                    @include('portal::partials.icon', ['name' => 'back'])
                    <span>Retour à {{ $principal->branding->displayName }}</span>
                </a>
            @endif
            @if ($principal->branding->supportUrl)
                <a class="nav__item nav__item--quiet" href="{{ $principal->branding->supportUrl }}" rel="noopener noreferrer">
                    @include('portal::partials.icon', ['name' => 'help'])
                    <span>Besoin d'aide ?</span>
                </a>
            @endif
            <form method="post" action="/portal/logout" class="who">
                <span class="who__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($principal->customerRef, 0, 1)) }}</span>
                <span class="who__ref" title="{{ $principal->customerRef }}">{{ $principal->customerRef }}</span>
                <input type="hidden" name="_csrf" value="{{ $csrf }}">
                <button type="submit" class="iconbtn" title="Se déconnecter" aria-label="Se déconnecter">@include('portal::partials.icon', ['name' => 'logout'])</button>
            </form>
        </footer>
    </div>
@endisset

    <div class="main">
        <main class="page" id="main" tabindex="-1">
            @isset($principal)
                {{-- A read session is a window: say so, on every page. --}}
                @if (($principal->readOnly ?? false))
                    <p class="note">Consultation seule : cette session montre le portail du client, sans permettre aucune action.</p>
                @endif
            @endisset
            @yield('content')
        </main>
    </div>
</body>
</html>
