@extends('portal::layout')
@section('title', 'Factures')
@section('content')
@php use Core\Portal\Application\Support\PortalFormat as F; @endphp
<h1>Factures</h1>
<section class="card card--flush" aria-labelledby="invoices-title">
    <h2 id="invoices-title" class="sr-only">Liste des factures</h2>
    @forelse ($invoices as $invoice)
        @php [$label, $tone] = F::invoiceStatus($invoice->status); @endphp
        <a class="row row--link" href="/portal/invoices/{{ $invoice->id }}">
            <div class="row__main">
                <strong>{{ $invoice->number ?? 'Facture' }}</strong>
                <span class="muted small">Émise le {{ F::date($invoice->issuedAt) }}@if($invoice->status === 'open' && $invoice->dueAt) · échéance {{ F::date($invoice->dueAt) }}@endif</span>
            </div>
            <div class="row__side">
                <span class="amount amount--sm">{{ F::money($invoice->totalMinor, $invoice->currency) }}</span>
                <span class="badge badge--{{ $tone }}">{{ $label }}</span>
            </div>
        </a>
    @empty
        <p class="muted pad">Aucune facture pour le moment.</p>
    @endforelse
</section>
@endsection
