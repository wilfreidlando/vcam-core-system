<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Paiement simulé</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 24px 16px; }
        .card { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        .badge { display: inline-block; background: #fef3c7; color: #92400e; font-weight: 600; font-size: 12px; border-radius: 6px; padding: 2px 8px; }
        h1 { font-size: 18px; font-weight: 700; margin: 12px 0 0; }
        .amount { font-size: 32px; font-weight: 700; margin: 12px 0 4px; }
        .muted { color: #64748b; font-size: 14px; }
        button { width: 100%; border: 0; border-radius: 8px; padding: 12px; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 10px; }
        .ok { background: #059669; color: #fff; } .ko { background: #e11d48; color: #fff; } .neutral { background: #e2e8f0; color: #0f172a; }
        .result { margin-top: 16px; padding: 12px; border-radius: 8px; background: #f1f5f9; }
    </style>
</head>
<body>
{{-- I1 — la page a un <main> et un <h1> : le titre de page est le premier repère
     lu par un lecteur d'écran (SC 1.3.1 / 2.4.6). --}}
<main class="card">
    <h1>Simulateur de paiement</h1>
    <span class="badge">SIMULATEUR — aucun argent réel</span>
    @if ($payment !== null)
        <p class="amount">{{ \Core\Support\Money\Money::ofMinor($payment->amount, $payment->currency)->formattedAmount() }} {{ $payment->currency }}</p>
        <p class="muted">Paiement {{ $payment->id }}</p>
    @endif

    @if ($outcome !== null)
        <div class="result">
            @if (in_array($outcome, ['succeeded', 'failed', 'expired', 'canceled'], true))
                Résultat simulé : <strong>{{ $outcome }}</strong>. Le Core a notifié l'intégrateur (webhook ou callback_url) comme pour un vrai paiement.
            @else
                {{ $outcome }}
            @endif
        </div>
        @if ($payment !== null && $payment->returnUrl !== null)
            <p><a href="{{ $payment->returnUrl }}">Revenir au site marchand</a></p>
        @endif
    @elseif ($action !== null && $payment !== null && ! $payment->status->isTerminal())
        <p class="muted">Choisissez le résultat à simuler :</p>
        <form method="POST" action="{{ $action }}">
            {{-- B35 — `type="submit"` explicite sur les quatre boutons de simulation. --}}
            <button type="submit" class="ok" name="status" value="succeeded">Payer (succès)</button>
            <button type="submit" class="ko" name="status" value="failed">Échec du paiement</button>
            <button type="submit" class="neutral" name="status" value="canceled">Annuler</button>
            <button type="submit" class="neutral" name="status" value="expired">Laisser expirer</button>
        </form>
    @elseif ($payment !== null)
        <div class="result">Ce paiement est déjà final : <strong>{{ $payment->status->value }}</strong>.</div>
    @endif
</main>
</body>
</html>
