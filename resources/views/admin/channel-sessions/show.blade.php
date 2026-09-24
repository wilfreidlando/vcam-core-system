@extends('layouts.admin')

@section('title', $label)
@section('subtitle', $sessionRef)

@section('content')
<div class="max-w-lg mx-auto space-y-4">
    <div class="bg-white border border-slate-200 rounded-lg p-6 text-center">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $label }} — {{ $sessionRef }}" class="mx-auto max-w-full rounded-md border border-slate-200">
            <p class="mt-3 text-base text-slate-600">Actualisez la page pour obtenir une nouvelle image.</p>
        @else
            <p class="text-base text-rose-700">{{ $error }}</p>
            @if ($label === 'Capture d\'écran')
                <p class="mt-2 text-sm text-slate-500">La capture d'écran n'est disponible que si le moteur WhatsApp du fournisseur est basé sur Chrome et que la session tourne. Pour savoir si un téléphone est lié, tentez plutôt un envoi.</p>
            @else
                <p class="mt-2 text-sm text-slate-500">Si la session est déjà liée à un téléphone, le QR code n'est plus disponible.</p>
            @endif
        @endif
    </div>
    <div class="text-right">
        <a href="{{ $backUrl }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">← Retour aux sessions</a>
    </div>
</div>
@endsection
