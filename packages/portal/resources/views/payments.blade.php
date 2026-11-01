@extends('portal::layout')
@section('title', 'Paiements')
@section('content')
@php use Core\Portal\Application\Support\PortalFormat as F; @endphp
<h1>Paiements</h1>
<section class="card card--flush">
    @forelse ($lines as $line)
        @php [$label, $tone] = F::paymentStatus($line->payment->status); @endphp
        <a class="row row--link" href="/portal/invoices/{{ $line->invoiceId }}">
            <div class="row__main">
                <strong>{{ F::money($line->payment->amountMinor, $line->payment->currency) }}</strong>
                <span class="muted small">{{ $line->invoiceNumber ?? 'Facture' }} · {{ F::date($line->payment->settledAt ?? $line->payment->createdAt) }}</span>
                @if ($line->payment->status === 'failed' && $line->payment->failureReason)
                    <span class="small failure">{{ $line->payment->failureReason }}</span>
                @endif
            </div>
            <span class="badge badge--{{ $tone }}">{{ $label }}</span>
        </a>
    @empty
        <p class="muted pad">Aucun paiement pour le moment.</p>
    @endforelse
</section>
<p class="muted small">Chaque ligne correspond à une tentative de paiement. Ouvrez la facture pour voir son détail et son reçu.</p>
@endsection
