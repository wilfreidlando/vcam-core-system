@extends('layouts.admin')

@section('title', "Exception d'argent")

@section('content')
<div class="space-y-6">
    @php
        $field = 'text-sm uppercase tracking-wider font-semibold text-slate-500';
        $fmt = static fn (?string $iso): string => $iso !== null ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y H:i:s') : '—';
        $money = static fn (int $minor) => \Core\Support\Money\Money::ofMinor($minor, $exception->currency)->formattedAmount().' '.$exception->currency;
        $statusLabels = ['open' => 'Ouverte', 'awaiting_approval' => 'En attente de validation', 'resolved' => 'Résolue'];
        $resolutionLabels = [
            'refunded_outside' => 'Remboursé hors plateforme',
            'recognized_as_revenue' => 'Passé en revenu du projet',
            'allocated_to_wallet' => 'Imputé à un wallet',
            'no_action' => "Aucun mouvement (rien n'était écrit)",
        ];
    @endphp

    @if (session('status'))
        <div class="rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex flex-wrap items-center gap-3 mb-2">
            <span class="text-2xl font-semibold text-slate-900">{{ $exception->kindLabel }}</span>
            <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ $statusLabels[$exception->status] ?? $exception->status }}</span>
        </div>
        <p class="text-base text-slate-700 mb-5">{{ $exception->summary }}</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4 text-base">
            <div>
                <p class="{{ $field }}">Montant en jeu</p>
                <p class="text-slate-900 tabular-nums">{{ $money($exception->amountMinor) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">En attente au grand livre</p>
                <p class="text-slate-900 tabular-nums">{{ $money($exception->bookedAmountMinor) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Gravité</p>
                <p class="text-slate-700">{{ $exception->severity }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Projet</p>
                @if ($projectName !== null)
                    <a href="{{ route('admin.projects.show', ['projectId' => $exception->projectId]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $projectName }}</a>
                @else
                    <p class="font-mono text-slate-700 break-all">{{ $exception->projectId }}</p>
                @endif
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Paiement</p>
                @if ($exception->paymentId !== null)
                    <a href="{{ route('admin.payments.show', ['payment' => $exception->paymentId, 'project_id' => $exception->projectId]) }}" class="text-indigo-600 hover:text-indigo-800 font-mono text-sm break-all">{{ $exception->paymentId }}</a>
                @else
                    <p class="text-slate-500">—</p>
                @endif
            </div>
            <div>
                <p class="{{ $field }}">Ouverte le</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($exception->createdAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Résolue le</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($exception->resolvedAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Référence externe</p>
                <p class="font-mono text-slate-700 break-all">{{ $exception->subjectRef ?? '—' }}</p>
            </div>
        </div>

        @if ($exception->resolution !== null)
            <div class="mt-6 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 space-y-1">
                <p><span class="font-semibold">Décision :</span> {{ $exception->resolutionLabel }}</p>
                @if ($exception->resolutionReference)
                    <p><span class="font-semibold">Référence :</span> <span class="font-mono">{{ $exception->resolutionReference }}</span></p>
                @endif
                <p><span class="font-semibold">Motif :</span> {{ $exception->resolutionNote }}</p>
                <p><span class="font-semibold">Proposée par :</span> <span class="font-mono">{{ $exception->resolvedBy }}</span></p>
                @if ($exception->approvedBy)
                    <p><span class="font-semibold">Validée par :</span> <span class="font-mono">{{ $exception->approvedBy }}</span></p>
                @endif
            </div>
        @endif
    </div>

    @if ($canWrite && $exception->status === 'open' && count($exception->allowedResolutions) > 0)
        <form method="POST" action="{{ route('admin.money-exceptions.resolve', $exception->id) }}" class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
            @csrf
            <h2 class="font-semibold text-slate-900">Décider</h2>
            <fieldset class="space-y-2">
                <legend class="{{ $field }} mb-1">Résolution</legend>
                @foreach ($exception->allowedResolutions as $resolution)
                    <label class="flex items-center gap-2 text-base text-slate-800">
                        <input type="radio" id="resolution-{{ $resolution }}" name="resolution" value="{{ $resolution }}" @checked(old('resolution') === $resolution) required>
                        {{ $resolutionLabels[$resolution] ?? $resolution }}
                    </label>
                @endforeach
            </fieldset>
            <div>
                <label for="reference" class="{{ $field }} block mb-1">Référence du remboursement (obligatoire pour un remboursement)</label>
                <input type="text" id="reference" name="reference" maxlength="200" value="{{ old('reference') }}" class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
            </div>
            <div>
                <label for="note" class="{{ $field }} block mb-1">Motif</label>
                <textarea id="note" name="note" rows="3" minlength="5" maxlength="1000" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">{{ old('note') }}</textarea>
            </div>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Enregistrer la décision</button>
        </form>
    @endif

    @if ($canWrite && $exception->status === 'awaiting_approval')
        <div class="bg-white border border-amber-200 rounded-lg p-6 space-y-3">
            <h2 class="font-semibold text-slate-900">Validation par une seconde personne</h2>
            @if ($exception->resolvedBy === $operatorId)
                <p class="text-sm text-slate-600">Vous avez proposé cette résolution : une autre personne doit la valider.</p>
            @else
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('admin.money-exceptions.approve', $exception->id) }}">
                        @csrf
                        <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Valider et écrire au grand livre</button>
                    </form>
                    <form method="POST" action="{{ route('admin.money-exceptions.reject', $exception->id) }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Refuser</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    <p><a href="{{ route('admin.money-exceptions.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800">← Toutes les exceptions</a></p>
</div>
@endsection
