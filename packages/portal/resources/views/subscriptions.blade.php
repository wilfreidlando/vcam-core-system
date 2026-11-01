@extends('portal::layout')
@section('title', 'Abonnement')
@section('content')
@php
    use Core\Portal\Application\Support\PortalFormat as F;
    // A read session shows the contract and refuses every gesture: the
    // buttons that would be refused are not drawn.
    $readOnly = ($principal->readOnly ?? false);
@endphp
<h1>Mon abonnement</h1>
@forelse ($subscriptions as $sub)
    @php [$label, $tone] = F::subscriptionStatus($sub->status); @endphp
    <section class="card" aria-labelledby="sub-{{ $loop->index }}-title">
        <div class="card__head">
            <h2 id="sub-{{ $loop->index }}-title" class="card__title">{{ ucfirst($sub->planCode) }}</h2>
            <span class="badge badge--{{ $tone }}">{{ $label }}</span>
        </div>
        <dl class="facts">
            <div><dt>Tarif</dt><dd>{{ F::money($sub->priceMinor, $sub->currency) }} {{ F::interval($sub->intervalUnit, $sub->intervalCount) }}</dd></div>
            @if ($sub->currentPeriodStart)
                <div><dt>Période en cours</dt><dd>{{ F::date($sub->currentPeriodStart) }} → {{ F::date($sub->currentPeriodEnd) }}</dd></div>
            @endif
            @if ($sub->trialEndsAt)
                <div><dt>Fin de l'essai</dt><dd>{{ F::date($sub->trialEndsAt) }}</dd></div>
            @endif
            <div><dt>Abonné depuis</dt><dd>{{ F::date($sub->createdAt) }}</dd></div>
        </dl>

        @if ($sub->pendingPlanVersionId)
            <p class="note">Un changement d'offre est programmé pour la fin de la période en cours.</p>
        @endif

        @if ($sub->cancelAtPeriodEnd)
            <p class="note">Ce contrat ne sera pas renouvelé : il se termine le {{ F::date($sub->currentPeriodEnd) }}. Vous gardez l'accès jusque-là.</p>
            @if ($principal->branding->allowSelfCancel && ! $readOnly)
                <form method="post" action="/portal/subscriptions/{{ $sub->id }}/keep" class="inline">
                    <input type="hidden" name="_csrf" value="{{ $csrf }}">
                    <button type="submit" class="button">Reprendre le renouvellement</button>
                </form>
            @endif
        @elseif (! $readOnly && $principal->branding->allowPlanChange && in_array($sub->status, ['canceled', 'expired'], true))
            <form method="post" action="/portal/subscriptions/{{ $sub->id }}/renew" class="inline">
                <input type="hidden" name="_csrf" value="{{ $csrf }}">
                <input type="hidden" name="nonce" value="{{ $nonce }}">
                <button type="submit" class="button">Renouveler mon abonnement</button>
                <span class="muted small">Un nouveau contrat sur la même offre ; sa facture vous attend ensuite.</span>
            </form>
        @elseif (! $readOnly && $principal->branding->allowSelfCancel && in_array($sub->status, ['active', 'trialing', 'past_due'], true))
            <form method="post" action="/portal/subscriptions/{{ $sub->id }}/cancel" class="inline">
                <input type="hidden" name="_csrf" value="{{ $csrf }}">
                <button type="submit" class="button button--danger-ghost">Ne pas renouveler</button>
                <span class="muted small">Vous gardez l'accès jusqu'à la fin de la période payée.</span>
            </form>
        @endif
    </section>
@empty
    <section class="card"><p class="muted">Vous n'avez aucun abonnement.</p></section>
@endforelse

<p><a class="button button--ghost" href="/portal/plans">Voir les offres et tarifs</a></p>
@endsection
