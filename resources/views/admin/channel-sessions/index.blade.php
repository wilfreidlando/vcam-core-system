@extends('layouts.admin')

@section('title', 'Sessions WhatsApp')
@section('subtitle', $projectName !== null ? $projectName : 'Plateforme — sessions globales')

@php
    $routeFor = fn (string $name, array $extra = []) => $projectId !== null
        ? route('admin.projects.channel-sessions.'.$name, ['projectId' => $projectId] + $extra)
        : route('admin.channel-sessions.'.$name, $extra);
@endphp

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->has('pairing'))
        <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-base text-rose-800">{{ $errors->first('pairing') }}</div>
    @endif

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-1">Sessions enregistrées</h2>
        <p class="text-base text-slate-600 mb-3">
            @if ($projectId !== null)
                Sessions propres à ce projet : seul ce projet peut envoyer avec elles.
            @else
                Sessions de la plateforme : tous les projets peuvent envoyer avec elles
                (en passant leur nom dans <code>variables.session</code>).
            @endif
            Un client ne crée jamais de session : c'est vous qui la créez ici, puis vous liez le téléphone.
        </p>

        @if (count($sessions) === 0)
            <p class="text-base text-slate-500">Aucune session pour le moment.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($sessions as $session)
                    <li class="py-3 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono font-medium text-slate-900">{{ $session->sessionRef }}</span>
                            <span class="text-sm text-slate-500">créée le {{ $session->createdAt }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                            <a href="{{ $routeFor('status', ['sessionRef' => $session->sessionRef]) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Statut</a>
                            <a href="{{ $routeFor('qr', ['sessionRef' => $session->sessionRef]) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Scanner (QR)</a>
                            <a href="{{ $routeFor('screenshot', ['sessionRef' => $session->sessionRef]) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Capture d'écran</a>
                            <form method="POST" action="{{ $routeFor('pairing-code', ['sessionRef' => $session->sessionRef]) }}" class="flex items-center gap-2">
                                @csrf
                                <input type="text" name="phone_number" placeholder="+2376XXXXXXXX" required
                                       class="w-40 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-800">Code d'appairage</button>
                            </form>
                            @php
                                $confirmMessage = $projectId === null
                                    ? "Session GLOBALE : tous les projets qui l'utilisent ne pourront plus envoyer, et le téléphone lié sera déconnecté. Supprimer « {$session->sessionRef} » ?"
                                    : "Supprimer « {$session->sessionRef} » ? Le téléphone lié sera déconnecté et ce projet ne pourra plus envoyer avec.";
                            @endphp
                            <form method="POST" action="{{ $routeFor('delete', ['sessionRef' => $session->sessionRef]) }}" class="ml-auto"
                                  onsubmit="return confirm(@js($confirmMessage))">
                                @csrf
                                <button type="submit" class="font-medium text-rose-600 hover:text-rose-800">Supprimer</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-1">Créer une session</h2>
        <p class="text-base text-slate-600 mb-3">
            La session est créée et démarrée chez le fournisseur WhatsApp, puis enregistrée ici. Elle
            reste inutilisable pour l'envoi tant que le téléphone n'est pas lié (scan du QR).
        </p>
        <form method="POST" action="{{ $routeFor('store') }}" class="space-y-3">
            @csrf
            <div>
                <label for="session_ref" class="block text-base font-medium text-slate-700 mb-1">Nom de la session</label>
                <input type="text" id="session_ref" name="session_ref" value="{{ old('session_ref') }}" required maxlength="64"
                       placeholder="ma-boutique" class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                <p class="mt-1 text-sm text-slate-500">Lettres, chiffres, « - » et « _ ». C'est ce nom que le client passe dans <code>variables.session</code>.</p>
                @error('session_ref') <p class="mt-1 text-sm text-rose-700">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Créer et démarrer</button>
        </form>
    </div>

    @if ($projectId !== null)
        <div class="text-right">
            <a href="{{ route('admin.projects.show', ['projectId' => $projectId]) }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">← Retour au projet</a>
        </div>
    @endif
</div>
@endsection
