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

    {{-- Statut et erreurs (dont `pairing`) : rendus par le layout (role="status" / role="alert"). --}}

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
            <p class="text-base text-slate-600">Aucune session pour le moment.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($sessions as $session)
                    <li class="py-3 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono font-medium text-slate-900">{{ $session->sessionRef }}</span>
                            <span class="text-sm text-slate-600">créée le {{ $session->createdAt }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                            <a href="{{ $routeFor('status', ['sessionRef' => $session->sessionRef]) }}" class="font-medium text-indigo-700 hover:text-indigo-900">Statut<span class="sr-only"> de la session {{ $session->sessionRef }}</span></a>
                            <a href="{{ $routeFor('qr', ['sessionRef' => $session->sessionRef]) }}" class="font-medium text-indigo-700 hover:text-indigo-900">Scanner (QR)<span class="sr-only"> de la session {{ $session->sessionRef }}</span></a>
                            <a href="{{ $routeFor('screenshot', ['sessionRef' => $session->sessionRef]) }}" class="font-medium text-indigo-700 hover:text-indigo-900">Capture d'écran<span class="sr-only"> de la session {{ $session->sessionRef }}</span></a>
                            <form method="POST" action="{{ $routeFor('pairing-code', ['sessionRef' => $session->sessionRef]) }}" class="flex items-center gap-2">
                                @csrf
                                {{-- B21–B23 : un vrai libellé (masqué), le placeholder n'est qu'un exemple. --}}
                                <label for="phone-{{ $session->sessionRef }}" class="sr-only">Numéro de téléphone à lier à la session {{ $session->sessionRef }}</label>
                                <input type="tel" id="phone-{{ $session->sessionRef }}" name="phone_number" placeholder="+2376XXXXXXXX" required autocomplete="tel"
                                       class="w-40 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                <button type="submit" class="rounded-md px-2.5 py-1.5 font-medium text-indigo-700 hover:bg-indigo-50">Code d'appairage</button>
                            </form>
                            @php
                                $confirmMessage = $projectId === null
                                    ? "Session GLOBALE : tous les projets qui l'utilisent ne pourront plus envoyer, et le téléphone lié sera déconnecté. Supprimer « {$session->sessionRef} » ?"
                                    : "Supprimer « {$session->sessionRef} » ? Le téléphone lié sera déconnecté et ce projet ne pourra plus envoyer avec.";
                            @endphp
                            <div class="ml-auto">
                                <x-admin.confirm-dialog
                                    title="Supprimer la session {{ $session->sessionRef }} ?"
                                    :message="$confirmMessage"
                                    :action="$routeFor('delete', ['sessionRef' => $session->sessionRef])"
                                    confirm="Supprimer">
                                    <x-slot:trigger>
                                        <button type="button" class="rounded-md px-2.5 py-1.5 font-medium text-rose-700 hover:bg-rose-50">Supprimer<span class="sr-only"> la session {{ $session->sessionRef }}</span></button>
                                    </x-slot:trigger>
                                </x-admin.confirm-dialog>
                            </div>
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
            <x-admin.errors />
            <x-admin.field name="session_ref" label="Nom de la session" required maxlength="64" placeholder="ma-boutique"
                           hint="Lettres, chiffres, « - » et « _ ». C'est ce nom que le client passe dans variables.session." />
            <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Créer et démarrer</button>
        </form>
    </div>

    @if ($projectId !== null)
        <div class="text-right">
            <a href="{{ route('admin.projects.show', ['projectId' => $projectId]) }}" class="text-sm text-indigo-700 hover:text-indigo-900 font-medium">← Retour au projet</a>
        </div>
    @endif
</div>
@endsection
