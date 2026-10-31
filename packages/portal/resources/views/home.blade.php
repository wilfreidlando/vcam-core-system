@extends('portal::layout')
@section('title', 'Accueil')
@section('content')
@php use Core\Portal\Application\Support\PortalFormat as F; @endphp
<h1 class="hello">Bonjour {{ $home->customerName }}</h1>

<section class="kpis" aria-label="Résumé">
    <article class="kpi {{ $home->dueByCurrency !== [] ? 'kpi--alert' : 'kpi--ok' }}">
        <h2 class="kpi__label">{{ $home->dueByCurrency !== [] ? 'À régler' : 'Solde' }}</h2>
        @if ($home->dueByCurrency !== [])
            @foreach ($home->dueByCurrency as $currency => $minor)
                <p class="kpi__value">{{ F::money($minor, $currency) }}</p>
            @endforeach
            <p class="kpi__hint">@if($home->nextDueAt)Échéance le {{ F::date($home->nextDueAt) }}@else{{ count($home->openInvoices) }} {{ count($home->openInvoices) > 1 ? 'factures' : 'facture' }} en attente @endif</p>
        @else
            <p class="kpi__value">À jour</p>
            <p class="kpi__hint">Aucune facture en attente</p>
        @endif
    </article>

    <article class="kpi">
        <h2 class="kpi__label">Prochain renouvellement</h2>
        <p class="kpi__value">{{ $home->nextRenewalAt ? F::date($home->nextRenewalAt) : '—' }}</p>
        <p class="kpi__hint">@if($home->nextRenewalAt){{ F::daysUntil($home->nextRenewalAt) }}@else Aucun contrat en cours @endif</p>
    </article>

    <article class="kpi">
        <h2 class="kpi__label">Payé sur 12 mois</h2>
        @forelse ($home->paidLast12Months as $currency => $minor)
            <p class="kpi__value">{{ F::money($minor, $currency) }}</p>
        @empty
            <p class="kpi__value">—</p>
        @endforelse
        <p class="kpi__hint">Total de vos règlements</p>
    </article>
</section>

@if ($home->dueByCurrency !== [])
    <section class="card card--alert">
        <div class="card__head">
            <h2 class="card__title">Factures à régler</h2>
        </div>
        @foreach ($home->openInvoices as $invoice)
            <a class="row row--link" href="/portal/invoices/{{ $invoice->id }}">
                <div class="row__main">
                    <strong>{{ $invoice->number ?? 'Facture' }}</strong>
                    @if ($invoice->dueAt)<span class="muted small">Échéance {{ F::date($invoice->dueAt) }}</span>@endif
                </div>
                <div class="row__side">
                    <span class="amount amount--sm">{{ F::money($invoice->totalMinor - $invoice->amountPaidMinor, $invoice->currency) }}</span>
                    @unless (($principal->readOnly ?? false))
                        <span class="button button--sm" aria-hidden="true">Payer</span>
                    @endunless
                </div>
            </a>
        @endforeach
    </section>
@endif

<section class="card" aria-labelledby="subs-title">
    <div class="card__head">
        <h2 id="subs-title" class="card__title">Votre abonnement</h2>
        <a class="card__more" href="/portal/subscriptions">Gérer<span class="sr-only"> mon abonnement</span></a>
    </div>
    @forelse ($home->subscriptions as $sub)
        @php [$label, $tone] = F::subscriptionStatus($sub->status); @endphp
        <div class="row">
            <div class="row__main">
                <strong>{{ ucfirst($sub->planCode) }}</strong>
                <span class="muted">{{ F::money($sub->priceMinor, $sub->currency) }} {{ F::interval($sub->intervalUnit, $sub->intervalCount) }}</span>
            </div>
            <span class="badge badge--{{ $tone }}">{{ $label }}</span>
        </div>
        @if ($sub->currentPeriodEnd)
            <p class="muted small">{{ $sub->cancelAtPeriodEnd ? 'Se termine le' : 'Prochaine échéance :' }} {{ F::date($sub->currentPeriodEnd) }}</p>
        @endif
    @empty
        <p class="muted">Aucun abonnement pour le moment.</p>
    @endforelse
</section>

@if ($home->entitlements && $home->entitlements->active)
    <section class="card" aria-labelledby="use-title">
        <div class="card__head">
            <h2 id="use-title" class="card__title">Utilisation</h2>
            <a class="card__more" href="/portal/usage">Tout voir<span class="sr-only"> l'utilisation</span></a>
        </div>
        @php $shown = 0; @endphp
        @foreach ($home->entitlements->entitlements as $e)
            @if ($e->type === 'limit' && $shown < 3)
                @php $shown++; @endphp
                @include('portal::partials.meter', ['e' => $e])
            @endif
        @endforeach
        @if ($shown === 0)
            <p class="muted">Aucun quota à suivre sur votre offre.</p>
        @endif
    </section>
@endif

@if (count($home->recentInvoices) > 0)
    <section class="card card--flush">
        <div class="card__head card__head--flush">
            <h2 class="card__title">Activité récente</h2>
            <a class="card__more" href="/portal/invoices">Toutes les factures</a>
        </div>
        @foreach ($home->recentInvoices as $invoice)
            @php [$ilabel, $itone] = F::invoiceStatus($invoice->status); @endphp
            <a class="row row--link" href="/portal/invoices/{{ $invoice->id }}">
                <div class="row__main">
                    <strong>{{ $invoice->number ?? 'Facture' }}</strong>
                    <span class="muted small">{{ F::date($invoice->issuedAt) }}</span>
                </div>
                <div class="row__side">
                    <span class="amount amount--sm">{{ F::money($invoice->totalMinor, $invoice->currency) }}</span>
                    <span class="badge badge--{{ $itone }}">{{ $ilabel }}</span>
                </div>
            </a>
        @endforeach
    </section>
@endif
@endsection
