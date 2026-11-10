@extends('portal::layout')
@section('title', 'Utilisation')
@section('content')
@php
    use Core\Portal\Application\Support\PortalFormat as F;
    $entitlements = $usage->entitlements;
    // Une session en consultation seule montre le portail sans
    // permettre aucun geste : le rechargement n'y est pas dessiné.
    $readOnly = ($principal->readOnly ?? false);
@endphp
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

    @if (count($usage->groups) > 0)
        <section class="card card--alert" aria-labelledby="recharge-title">
            <h2 id="recharge-title" class="card__title">Recharger mon quota</h2>
            @foreach ($usage->groups as $group)
                <p class="note">{{ $group->message }}</p>
                @foreach ($group->packs as $pack)
                    <div class="row">
                        <div class="row__main">
                            <strong>{{ $pack->label }}</strong>
                            <span class="muted small">{{ F::count($pack->quantity) }} {{ $group->subject }} — {{ F::money($pack->totalMinor, $pack->currency) }} <span class="muted small">(hors taxes)</span></span>
                        </div>
                        @if ($readOnly)
                            <span class="muted small">Consultation seule</span>
                        @else
                            <form method="post" action="/portal/packs" class="inline">
                                <input type="hidden" name="_csrf" value="{{ $csrf }}">
                                <input type="hidden" name="pack" value="{{ $pack->code }}">
                                <input type="hidden" name="nonce" value="{{ $usage->nonce }}">
                                <button type="submit" class="button button--sm"
                                        aria-label="Recharger : {{ $pack->label }}">{{ $pack->label }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            @endforeach
            <p class="muted small">Un rechargement est une facture à part : votre abonnement n'est pas renouvelé.</p>
        </section>
    @endif

    @php $features = array_values(array_filter($entitlements->entitlements, fn ($e) => $e->type === 'boolean')); @endphp
    @if (count($features) > 0)
        <section class="card" aria-labelledby="features-title">
            <h2 id="features-title" class="card__title">Fonctionnalités incluses</h2>
            <ul class="ticks">
                @foreach ($features as $f)
                    <li @class(['is-off' => ! $f->enabled])><span class="ticks__mark" aria-hidden="true">{{ $f->enabled ? '✓' : '–' }}</span>{{ str_replace(['_', '.'], ' ', $f->key) }} <span class="sr-only">({{ $f->enabled ? 'inclus' : 'non inclus' }})</span></li>
                @endforeach
            </ul>
        </section>
    @endif
@else
    <section class="card"><p class="muted">Aucun abonnement actif : il n'y a pas d'utilisation à suivre.</p></section>
@endif
@endsection
