@extends('portal::layout')
@section('title', 'Utilisation')
@section('content')
<h1>Utilisation</h1>
@if ($entitlements && $entitlements->active)
    <section class="card">
        @php $any = false; @endphp
        @foreach ($entitlements->entitlements as $e)
            @if ($e->type === 'limit')
                @php $any = true; @endphp
                @include('portal::partials.meter', ['e' => $e])
            @endif
        @endforeach
        @unless ($any)
            <p class="muted">Votre offre n'a aucun quota à suivre.</p>
        @endunless
    </section>
    @php $features = array_values(array_filter($entitlements->entitlements, fn ($e) => $e->type === 'boolean')); @endphp
    @if (count($features) > 0)
        <section class="card">
            <h2 class="card__title">Fonctionnalités incluses</h2>
            <ul class="ticks">
                @foreach ($features as $f)
                    <li @class(['is-off' => ! $f->enabled])>{{ str_replace(['_', '.'], ' ', $f->key) }}@unless ($f->enabled) <span class="sr-only">(non inclus)</span>@endunless</li>
                @endforeach
            </ul>
        </section>
    @endif
@else
    <section class="card"><p class="muted">Aucun abonnement actif : il n'y a pas d'utilisation à suivre.</p></section>
@endif
@endsection
