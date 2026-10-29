@extends('portal::layout')
@section('title', trim('Facture '.($detail->invoice->number ?? '')))
@section('content')
@php
    use Core\Portal\Application\Support\PortalFormat as F;
    $invoice = $detail->invoice;
    [$label, $tone] = F::invoiceStatus($invoice->status);
@endphp
<p class="crumb"><a href="/portal/invoices">← Factures</a></p>
<h1>{{ $invoice->number ?? 'Facture' }} <span class="badge badge--{{ $tone }}">{{ $label }}</span></h1>

@if ($error)
    <div class="alert" id="pay-error" role="alert" tabindex="-1">{{ $error }}</div>
@endif

<section class="card">
    <table class="lines">
        <caption class="sr-only">Détail de la facture</caption>
        <thead><tr><th scope="col">Désignation</th><th scope="col" class="num">Qté</th><th scope="col" class="num">Montant</th></tr></thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line->description }}@if($line->periodStart)<br><span class="muted small">{{ F::date($line->periodStart) }} → {{ F::date($line->periodEnd) }}</span>@endif</td>
                <td class="num">{{ $line->quantity }}</td>
                <td class="num">{{ F::money($line->amountMinor, $invoice->currency) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            @if ($invoice->taxMinor > 0)
                <tr><th scope="row" colspan="2">Sous-total</th><td class="num">{{ F::money($invoice->subtotalMinor, $invoice->currency) }}</td></tr>
                <tr><th scope="row" colspan="2">Taxes</th><td class="num">{{ F::money($invoice->taxMinor, $invoice->currency) }}</td></tr>
            @endif
            <tr class="total"><th scope="row" colspan="2">Total</th><td class="num">{{ F::money($invoice->totalMinor, $invoice->currency) }}</td></tr>
        </tfoot>
    </table>
    <dl class="facts">
        <div><dt>Émise le</dt><dd>{{ F::date($invoice->issuedAt) }}</dd></div>
        @if ($invoice->status === 'open' && $invoice->dueAt)<div><dt>Échéance</dt><dd>{{ F::date($invoice->dueAt) }}</dd></div>@endif
        @if ($invoice->paidAt)<div><dt>Payée le</dt><dd>{{ F::date($invoice->paidAt) }}</dd></div>@endif
    </dl>
</section>

@if ($detail->payable)
    <section class="card card--alert" aria-labelledby="pay-title">
        <h2 id="pay-title" class="card__title">Payer cette facture</h2>
        <form method="post" action="/portal/invoices/{{ $invoice->id }}/pay" class="form">
            <input type="hidden" name="_csrf" value="{{ $csrf }}">
            <input type="hidden" name="nonce" value="{{ $detail->formNonce }}">

            <fieldset class="choices">
                <legend>Moyen de paiement</legend>
                @foreach ($detail->channels as $channel)
                    <label class="choice">
                        <input type="radio" name="channel" value="{{ $channel }}" @checked($loop->first) required>
                        <span>{{ F::channel($channel) }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="field">
                <label class="field__label" for="phone">Numéro de téléphone du compte</label>
                <span id="phone-hint" class="muted small">Format : +237 6XX XX XX XX, avec l'indicatif du pays.</span>
                <input class="field__input" id="phone" type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="+237 6XX XX XX XX" aria-describedby="{{ $error ? 'pay-error phone-hint' : 'phone-hint' }}" @if($error) aria-invalid="true" @endif required>
            </div>

            <button type="submit" class="button button--block">Payer {{ F::money($invoice->totalMinor - $invoice->amountPaidMinor, $invoice->currency) }}</button>
        </form>
    </section>
@elseif ($invoice->status === 'open')
    <p class="note">Le paiement en ligne n'est pas disponible pour cette facture. Contactez le support pour la régler.</p>
@endif

@if (count($detail->payments) > 0)
    <section class="card card--flush">
        <h2 class="card__title pad">Paiements</h2>
        @foreach ($detail->payments as $payment)
            @php [$plabel, $ptone] = F::paymentStatus($payment->status); @endphp
            <div class="row">
                <div class="row__main">
                    <strong>{{ F::money($payment->amountMinor, $payment->currency) }}</strong>
                    <span class="muted small">{{ F::date($payment->settledAt ?? $payment->createdAt) }}</span>
                </div>
                <span class="badge badge--{{ $ptone }}">{{ $plabel }}</span>
            </div>
        @endforeach
    </section>
@endif
@endsection
