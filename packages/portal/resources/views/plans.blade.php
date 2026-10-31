@extends('portal::layout')
@section('title', 'Offres et tarifs')
@section('content')
@php use Core\Portal\Application\Support\PortalFormat as F; @endphp
<header class="pricing-head">
    <h1>Choisissez l'offre qui vous convient</h1>
    <p class="muted">
        @if ($plans->canSubscribe)
            Choisissez votre offre : votre première facture vous attend ensuite dans « Factures ».
        @elseif ($plans->canChange())
            Changez d'offre quand vous voulez : le nouveau tarif s'applique à la fin de votre période en cours, sans frais surprise.
        @else
            Voici les offres proposées par {{ $principal->branding->displayName }}.
        @endif
    </p>
</header>

@if (count($plans->options) === 0)
    <section class="card"><p class="muted">Aucune offre n'est proposée pour le moment.</p></section>
@else
    <div class="pricing">
        @foreach ($plans->options as $plan)
            <article @class(['tier', 'is-current' => $plan->isCurrent])>
                @if ($plan->isCurrent)<span class="tier__flag">Votre offre</span>@endif
                <h2 class="tier__name" id="plan-{{ $loop->index }}-name">{{ $plan->name }}</h2>
                @if ($plan->description)<p class="tier__desc">{{ $plan->description }}</p>@endif

                <p class="tier__price">
                    <strong>{{ F::money($plan->priceMinor, $plan->currency) }}</strong>
                    <span>{{ F::interval($plan->intervalUnit, $plan->intervalCount) }}</span>
                </p>

                <ul class="tier__list">
                    @foreach ($plan->highlights as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>

                @if ($plan->isCurrent)
                    <p class="tier__state">Offre actuelle</p>
                @elseif (! ($principal->readOnly ?? false) && $plans->canSubscribe)
                    <form method="post" action="/portal/plans/{{ $plan->code }}/subscribe">
                        <input type="hidden" name="_csrf" value="{{ $csrf }}">
                        <input type="hidden" name="nonce" value="{{ $plans->formNonce }}">
                        <button type="submit" class="button button--block" aria-describedby="plan-{{ $loop->index }}-name">Souscrire</button>
                    </form>
                @elseif (! ($principal->readOnly ?? false) && $plans->canChange())
                    <form method="post" action="/portal/subscriptions/{{ $plans->changeableSubscriptionId }}/change-plan">
                        <input type="hidden" name="_csrf" value="{{ $csrf }}">
                        <input type="hidden" name="plan" value="{{ $plan->code }}">
                        <button type="submit" class="button button--block" aria-describedby="plan-{{ $loop->index }}-name">Choisir cette offre</button>
                    </form>
                @endif
            </article>
        @endforeach
    </div>
    @if (($principal->readOnly ?? false))
        <p class="muted small pricing-note">Consultation seule : aucune offre ne peut être souscrite ni changée depuis cette session.</p>
    @elseif (! $plans->canChange() && ! $plans->canSubscribe)
        <p class="muted small pricing-note">Pour changer d'offre, contactez {{ $principal->branding->displayName }}.</p>
    @endif
@endif
@endsection
