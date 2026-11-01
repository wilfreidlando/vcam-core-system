@extends('portal::layout')
@section('title', 'Paiement')
@section('content')
<section class="notice">
    @if ($outcome->requiresAction && $outcome->paymentUrl)
        <h1>Dernière étape</h1>
        <p>Continuez sur la page de paiement sécurisée pour confirmer.</p>
        <p><a class="button" href="{{ $outcome->paymentUrl }}" rel="noopener noreferrer">Continuer vers le paiement</a></p>
    @elseif ($outcome->requiresAction)
        <h1>Confirmez sur votre téléphone</h1>
        <p>{{ $outcome->actionHint ?? 'Une demande de confirmation vient d\'être envoyée sur votre téléphone. Validez-la avec votre code secret.' }}</p>
    @else
        <h1>Paiement en cours de traitement</h1>
        <p>Nous attendons la confirmation de l'opérateur.</p>
    @endif
    <p class="muted">Revenez sur la facture pour voir son état.</p>
    <p><a class="button button--ghost" href="/portal/invoices/{{ $outcome->invoiceId }}">Voir ma facture</a></p>
</section>
@endsection
